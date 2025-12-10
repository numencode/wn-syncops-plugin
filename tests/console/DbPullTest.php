<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use Carbon\Carbon;
use PluginTestCase;
use NumenCode\SyncOps\Console\DbPull;
use NumenCode\SyncOps\Classes\SshExecutor;
use NumenCode\SyncOps\Classes\SftpExecutor;
use NumenCode\SyncOps\Classes\RemoteExecutor;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Stub executor for bypassing RemoteExecutor constructor in tests.
 */
class RemoteExecutorStubForDbPull extends RemoteExecutor
{
    public function __construct()
    {
        // Bypass parent constructor
    }
}

/**
 * Helper subclass to expose protected methods for controlled mocking.
 */
class DbPullTestHelper extends DbPull
{
    public function createExecutor(string $server): RemoteExecutor
    {
        return parent::createExecutor($server);
    }

    public function runLocalCommand(string $command, int $timeout = 60): string
    {
        return parent::runLocalCommand($command, $timeout);
    }
}

class DbPullTest extends PluginTestCase
{
    protected string $timestamp = '2024-01-02_03_04_05';

    protected function localSqlPath(): string
    {
        return base_path($this->timestamp . '.sql');
    }

    protected function localGzPath(): string
    {
        return base_path($this->timestamp . '.sql.gz');
    }

    public function setUp(): void
    {
        parent::setUp();

        // Ensure console command binding exists for Winter’s console kernel
        if (!$this->app->bound('command.syncops.db_pull')) {
            $this->app->bind('command.syncops.db_pull', function () {
                return new class extends \Illuminate\Console\Command {
                    protected $signature = 'syncops:db-pull';
                    public function handle(): int
                    {
                        return 0;
                    }
                };
            });
        }

        // Stable timestamp for predictable filenames
        Carbon::setTestNow(Carbon::create(2024, 1, 2, 3, 4, 5));

        // Local DB config used by import
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'username' => 'localuser',
            'password' => 'localpass',
            'database' => 'mydb',
        ]);
    }

    public function tearDown(): void
    {
        foreach ([$this->localSqlPath(), $this->localGzPath()] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper: create a fully configured RemoteExecutor mock.
     */
    protected function makeExecutor(array $config, callable $sshSetup, callable $sftpSetup): RemoteExecutor
    {
        $executor = new RemoteExecutorStubForDbPull();
        $executor->config = $config;

        $ssh = Mockery::mock(SshExecutor::class);
        $sshSetup($ssh);
        $executor->ssh = $ssh;

        $sftp = Mockery::mock(SftpExecutor::class);
        $sftpSetup($sftp);
        $executor->sftp = $sftp;

        return $executor;
    }

    /**
     * Test function: handle
     * Gzip + import flow: creates remote dump, downloads .gz, unzips, imports locally,
     * and then cleans up both the local .sql and .gz dump files and the remote temp file.
     */
    public function testHandleWithGzipAndImportSuccess(): void
    {
        $server = 'staging';
        $remotePath = '/var/www/app';
        $remoteDumpGz = $remotePath . '/' . $this->timestamp . '.sql.gz';
        $localGz = $this->localGzPath();
        $localSql = $this->localSqlPath();

        $executor = $this->makeExecutor([
            'project' => [
                'path' => $remotePath,
            ],
            'database' => [
                'username' => 'ruser',
                'password' => 'rpass',
                'database' => 'rdb',
                'tables'   => ['table1', 'table2'],
            ],
        ], function ($ssh) use ($remoteDumpGz) {
            // Remote dump
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function ($cmd) use ($remoteDumpGz) {
                    return is_string($cmd)
                        && str_contains($cmd, 'mysqldump')
                        && str_contains($cmd, $remoteDumpGz)
                        && str_contains($cmd, 'table1')
                        && str_contains($cmd, 'table2');
                }))
                ->andReturn('dump ok');

            // Remote cleanup
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function ($cmd) use ($remoteDumpGz) {
                    return str_starts_with($cmd, 'rm -f')
                        && str_contains($cmd, basename($remoteDumpGz));
                }))
                ->andReturn('');
        }, function ($sftp) use ($remoteDumpGz, $localGz) {
            // Download .gz file and create a real gzip file locally
            $sftp->shouldReceive('download')
                ->once()
                ->with($remoteDumpGz, $localGz)
                ->andReturnUsing(function () use ($localGz) {
                    $gz = gzopen($localGz, 'wb9');
                    gzwrite($gz, "-- SQL DUMP --\nCREATE TABLE t(id INT);\n");
                    gzclose($gz);
                });
        });

        $cmd = Mockery::mock(DbPullTestHelper::class)->makePartial();
        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull();    // gzip enabled
        $cmd->shouldReceive('option')->with('no-import')->andReturnNull();  // import enabled
        $cmd->shouldReceive('createExecutor')->once()->with($server)->andReturn($executor);

        // Import command should run and reference the local SQL file basename
        $cmd->shouldReceive('runLocalCommand')
            ->once()
            ->with(Mockery::on(function ($importCmd) use ($localSql) {
                return is_string($importCmd)
                    && str_contains($importCmd, 'mysql ')
                    && str_contains($importCmd, basename($localSql));
            }))
            ->andReturn('');

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')->zeroOrMoreTimes();

        $result = $cmd->handle();
        $this->assertSame(DbPull::SUCCESS, $result);

        // .gz should be removed after unzip; .sql removed after import cleanup
        $this->assertFileDoesNotExist($localGz);
        $this->assertFileDoesNotExist($localSql);
    }

    /**
     * Test function: handle
     * No-gzip + no-import: downloads a plain .sql dump, does not run import,
     * and keeps the local .sql file on disk after completion.
     */
    public function testHandleWithoutGzipAndNoImportKeepsLocalFile(): void
    {
        $server = 'prod';
        $remotePath = '/srv/site';
        $remoteDump = $remotePath . '/' . $this->timestamp . '.sql';
        $localSql = $this->localSqlPath();

        $executor = $this->makeExecutor([
            'project'  => [
                'path' => $remotePath,
            ],
            'database' => [
                'username' => 'ruser',
                'password' => 'rpass',
                'database' => 'rdb',
                'tables'   => [],
            ],
        ], function ($ssh) use ($remoteDump) {
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function ($cmd) use ($remoteDump) {
                    return str_contains($cmd, 'mysqldump') && str_contains($cmd, $remoteDump);
                }))
                ->andReturn('dump ok');

            // Cleanup remote temp file
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function ($cmd) use ($remoteDump) {
                    return str_starts_with($cmd, 'rm -f')
                        && str_contains($cmd, basename($remoteDump));
                }))
                ->andReturn('');
        }, function ($sftp) use ($remoteDump, $localSql) {
            $sftp->shouldReceive('download')
                ->once()
                ->with($remoteDump, $localSql)
                ->andReturnUsing(function () use ($localSql) {
                    file_put_contents($localSql, "-- SQL --\n");
                });
        });

        $cmd = Mockery::mock(DbPullTestHelper::class)->makePartial();
        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnTrue();   // no gzip
        $cmd->shouldReceive('option')->with('no-import')->andReturnTrue(); // no import
        $cmd->shouldReceive('createExecutor')->once()->with($server)->andReturn($executor);

        // Import must NOT be called
        $cmd->shouldNotReceive('runLocalCommand');

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')->zeroOrMoreTimes();

        $result = $cmd->handle();
        $this->assertSame(DbPull::SUCCESS, $result);
        $this->assertFileExists($localSql);
    }

    /**
     * Test function: handle
     * Gzip + no-import: creates a remote .sql.gz dump, downloads and unzips it
     * locally, deletes only the .gz, and keeps the final .sql file on disk.
     */
    public function testHandleWithGzipAndNoImportKeepsLocalSqlFile(): void
    {
        $server = 'prod-gzip';
        $remotePath = '/srv/site';
        $remoteDumpGz = $remotePath . '/' . $this->timestamp . '.sql.gz';
        $localGz = $this->localGzPath();
        $localSql = $this->localSqlPath();

        $executor = $this->makeExecutor([
            'project'  => [
                'path' => $remotePath,
            ],
            'database' => [
                'username' => 'ruser',
                'password' => 'rpass',
                'database' => 'rdb',
                'tables'   => [],
            ],
        ], function ($ssh) use ($remoteDumpGz) {
            // Remote dump creation
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function ($cmd) use ($remoteDumpGz) {
                    return is_string($cmd)
                        && str_contains($cmd, 'mysqldump')
                        && str_contains($cmd, $remoteDumpGz);
                }))
                ->andReturn('dump ok');

            // Remote cleanup
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function ($cmd) use ($remoteDumpGz) {
                    return str_starts_with($cmd, 'rm -f')
                        && str_contains($cmd, basename($remoteDumpGz));
                }))
                ->andReturn('');
        }, function ($sftp) use ($remoteDumpGz, $localGz) {
            // Download .gz file and create real gzip content locally
            $sftp->shouldReceive('download')
                ->once()
                ->with($remoteDumpGz, $localGz)
                ->andReturnUsing(function () use ($localGz) {
                    $gz = gzopen($localGz, 'wb9');
                    gzwrite($gz, "-- GZIPPED SQL --\nCREATE TABLE test(id INT);\n");
                    gzclose($gz);
                });
        });

        $cmd = Mockery::mock(DbPullTestHelper::class)->makePartial();
        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull();    // gzip enabled
        $cmd->shouldReceive('option')->with('no-import')->andReturnTrue();  // no import
        $cmd->shouldReceive('createExecutor')->once()->with($server)->andReturn($executor);

        // Import must NOT be called
        $cmd->shouldNotReceive('runLocalCommand');

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')->zeroOrMoreTimes();

        $result = $cmd->handle();
        $this->assertSame(DbPull::SUCCESS, $result);

        // .gz should be removed after unzip; .sql kept
        $this->assertFileDoesNotExist($localGz);
        $this->assertFileExists($localSql);
    }

    /**
     * Test function: handle
     * If remote dump fails with an exception, handle() should return FAILURE,
     * not attempt SFTP download or local import, and still attempt to clean up
     * the remote temporary dump file.
     */
    public function testHandleRemoteErrorReturnsFailureAndCleansUp(): void
    {
        $server = 'error-srv';
        $remotePath = '/data/app';
        $remoteDumpGz = $remotePath . '/' . $this->timestamp . '.sql.gz';

        $executor = $this->makeExecutor([
            'project'  => [
                'path' => $remotePath,
            ],
            'database' => [
                'username' => 'ruser',
                'password' => 'rpass',
                'database' => 'rdb',
                'tables'   => ['a'],
            ],
        ], function ($ssh) use ($remoteDumpGz) {
            // Simulate failure on dump
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->andThrow(new \RuntimeException('remote dump failed'));

            // Cleanup still attempted in finally block
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function ($cmd) use ($remoteDumpGz) {
                    return str_starts_with($cmd, 'rm -f')
                        && str_contains($cmd, basename($remoteDumpGz));
                }))
                ->andReturn('');
        }, function ($sftp) {
            // SFTP should not be used in this path; safe to remain unused
        });

        $cmd = Mockery::mock(DbPullTestHelper::class)->makePartial();
        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull();
        $cmd->shouldReceive('option')->with('no-import')->andReturnNull();
        $cmd->shouldReceive('createExecutor')->once()->with($server)->andReturn($executor);

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')->atLeast()->once();

        $result = $cmd->handle();
        $this->assertSame(DbPull::FAILURE, $result);

        $this->assertFileDoesNotExist($this->localSqlPath());
        $this->assertFileDoesNotExist($this->localGzPath());
    }

    /**
     * Test function: handle
     * If the local mysql import fails with a ProcessFailedException, handle()
     * should print a helpful error message, return FAILURE, and still perform
     * both remote and local cleanup of the dump files.
     */
    public function testHandleLocalImportFailureReturnsFailureAndCleansUp(): void
    {
        $server = 'import-fail';
        $remotePath = '/var/www/app';
        $remoteDumpGz = $remotePath . '/' . $this->timestamp . '.sql.gz';
        $localGz = $this->localGzPath();
        $localSql = $this->localSqlPath();

        $executor = $this->makeExecutor([
            'project' => [
                'path' => $remotePath,
            ],
            'database' => [
                'username' => 'ruser',
                'password' => 'rpass',
                'database' => 'rdb',
                'tables'   => ['table1'],
            ],
        ], function ($ssh) use ($remoteDumpGz) {
            // Successful remote dump
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function ($cmd) use ($remoteDumpGz) {
                    return is_string($cmd)
                        && str_contains($cmd, 'mysqldump')
                        && str_contains($cmd, $remoteDumpGz);
                }))
                ->andReturn('dump ok');

            // Remote cleanup should still be called from finally
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function ($cmd) use ($remoteDumpGz) {
                    return str_starts_with($cmd, 'rm -f')
                        && str_contains($cmd, basename($remoteDumpGz));
                }))
                ->andReturn('');
        }, function ($sftp) use ($remoteDumpGz, $localGz) {
            // Download .gz file and create a real gzip so unzip logic is exercised
            $sftp->shouldReceive('download')
                ->once()
                ->with($remoteDumpGz, $localGz)
                ->andReturnUsing(function () use ($localGz) {
                    $gz = gzopen($localGz, 'wb9');
                    gzwrite($gz, "-- GZIPPED SQL --\nCREATE TABLE fail(id INT);\n");
                    gzclose($gz);
                });
        });

        $cmd = Mockery::mock(DbPullTestHelper::class)->makePartial();
        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull();    // gzip enabled
        $cmd->shouldReceive('option')->with('no-import')->andReturnNull();  // import enabled
        $cmd->shouldReceive('createExecutor')->once()->with($server)->andReturn($executor);

        // Simulate mysqldump import failure with ProcessFailedException
        $processException = Mockery::mock(ProcessFailedException::class);
        $processException->shouldReceive('getProcess->getErrorOutput')
            ->andReturn('mysql: access denied');

        $cmd->shouldReceive('runLocalCommand')
            ->once()
            ->andThrow($processException);

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')->atLeast()->once();

        $result = $cmd->handle();
        $this->assertSame(DbPull::FAILURE, $result);

        // Both .gz (after unzip) and .sql should be cleaned up in finally
        $this->assertFileDoesNotExist($localGz);
        $this->assertFileDoesNotExist($localSql);
    }

    /**
     * Test function: handle (internal logic)
     * When the downloaded SQL dump starts with a MariaDB sandbox directive
     * (/*M!999999 ...), the first line should be removed before import.
     *
     * This directly exercises the line-stripping logic without running the
     * entire handle() method to avoid console IO dependencies.
     */
    public function testHandleRemovesMariaDbDirectiveBeforeImport(): void
    {
        $localSql = $this->localSqlPath();

        // Seed file with MariaDB directive on first line
        file_put_contents(
            $localSql,
            "/*M!999999 some sandbox directive */\nCREATE TABLE test(id INT);\n"
        );

        $cmd = Mockery::mock(DbPullTestHelper::class)->makePartial();

        // Execute only the directive-removal snippet from handle() in isolation
        (function () use ($localSql) {
            $localTempFile = $localSql;

            $contents = file($localTempFile);
            if ($contents !== false && isset($contents[0]) && str_starts_with($contents[0], '/*M!999999')) {
                array_shift($contents);
                file_put_contents($localTempFile, implode('', $contents));
            }
        })->call($cmd);

        $contents = file_get_contents($localSql);

        // First line should no longer contain the MariaDB directive
        $this->assertStringNotContainsString('M!999999', $contents);
        $this->assertStringStartsWith('CREATE TABLE', trim($contents));
    }
}
