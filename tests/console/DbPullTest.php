<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use Carbon\Carbon;
use PluginTestCase;
use NumenCode\SyncOps\Console\DbPull;
use NumenCode\SyncOps\Classes\SshExecutor;
use NumenCode\SyncOps\Classes\SftpExecutor;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class RemoteExecutorStubForDbPull extends RemoteExecutor
{
    public function __construct()
    {
        /* bypass parent */
    }
}

class DbPullTestHelper extends DbPull
{
    // Expose protected methods as public for testing/mocking
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

    public function setUp(): void
    {
        parent::setUp();

        // Ensure console command binding exists (AFTER parent::setUp(), when app is available)
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
        // Attempt cleanup of created files
        $paths = [
            base_path($this->timestamp . '.sql.gz'),
            base_path($this->timestamp . '.sql'),
        ];

        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: handle
     * Gzip + import flow: creates remote dump, downloads .gz, unzips, imports locally, cleans up.
     */
    public function testHandleWithGzipAndImportSuccess(): void
    {
        $server = 'staging';
        $remotePath = '/var/www/app';
        $remoteDumpGz = $remotePath . '/' . $this->timestamp . '.sql.gz';
        $localGz = base_path($this->timestamp . '.sql.gz');
        $localSql = base_path($this->timestamp . '.sql');

        // Build executor stub with config expected by DbPull
        $executor = new RemoteExecutorStubForDbPull();
        $executor->config = [
            'path' => $remotePath,
            'database' => [
                'username' => 'ruser',
                'password' => 'rpass',
                'database' => 'rdb',
                'tables'   => ['table1', 'table2'],
            ],
        ];

        // SSH expectations: dump command and cleanup rm -f $ssh
        $ssh = Mockery::mock(SshExecutor::class);
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

        // Cleanup: rm -f remote file
        $ssh->shouldReceive('runRawCommand')
            ->once()
            ->with(Mockery::on(function ($cmd) use ($remoteDumpGz) {
                return str_starts_with($cmd, 'rm -f') && str_contains($cmd, basename($remoteDumpGz));
            }))
            ->andReturn('');

        $executor->ssh = $ssh;

        // SFTP download will create the gz file locally
        $sftp = Mockery::mock(SftpExecutor::class);
        $sftp->shouldReceive('download')
            ->once()
            ->with($remoteDumpGz, $localGz)
            ->andReturnUsing(function ($remote, $local) {
                $gz = gzopen($local, 'wb9');
                gzwrite($gz, "-- SQL DUMP --\nCREATE TABLE t(id INT);\n");
                gzclose($gz);
                return null;
            });

        $executor->sftp = $sftp;

        // Partial mock the command (using helper subclass to expose methods)
        $cmd = Mockery::mock(DbPullTestHelper::class)->makePartial();
        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull(); // gzip enabled
        $cmd->shouldReceive('option')->with('no-import')->andReturnNull(); // import enabled

        // Allow console noise
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('comment')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();
        $cmd->shouldReceive('error')->zeroOrMoreTimes();

        // Replace executor creation
        $cmd->shouldReceive('createExecutor')->once()->with($server)->andReturn($executor);

        // Expect local import to be executed with a command string containing mysql and local SQL basename.
        // Relaxed matcher: test for 'mysql ' and the basename of the SQL file to be robust against quoting/escaping.
        $cmd->shouldReceive('runLocalCommand')
            ->once()
            ->with(Mockery::on(function ($importCmd) use ($localSql) {
                return is_string($importCmd)
                    && str_contains($importCmd, 'mysql ')
                    && str_contains($importCmd, basename($localSql));
            }))
            ->andReturn('');

        $result = $cmd->handle();
        $this->assertSame(DbPull::SUCCESS, $result);

        // Gz should have been removed after unzip; SQL should have been removed after import cleanup
        $this->assertFileDoesNotExist($localGz);
        $this->assertFileDoesNotExist($localSql);
    }

    /**
     * Test function: handle
     * No-gzip + no-import: downloads plain .sql and keeps it locally; import not executed.
     */
    public function testHandleWithoutGzipAndNoImportKeepsLocalFile(): void
    {
        $server = 'prod';
        $remotePath = '/srv/site';
        $remoteDump = $remotePath . '/' . $this->timestamp . '.sql';
        $localSql = base_path($this->timestamp . '.sql');

        $executor = new RemoteExecutorStubForDbPull();
        $executor->config = [
            'path' => $remotePath,
            'database' => [
                'username' => 'ruser',
                'password' => 'rpass',
                'database' => 'rdb',
                'tables'   => [],
            ],
        ];

        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('runRawCommand')->once()->with(Mockery::on(function ($cmd) use ($remoteDump) {
            return str_contains($cmd, 'mysqldump') && str_contains($cmd, $remoteDump);
        }))->andReturn('dump ok');

        // Cleanup of remote temp
        $ssh->shouldReceive('runRawCommand')->once()->with(Mockery::on(function ($cmd) use ($remoteDump) {
            return str_starts_with($cmd, 'rm -f') && str_contains($cmd, basename($remoteDump));
        }))->andReturn('');

        $executor->ssh = $ssh;

        $sftp = Mockery::mock(SftpExecutor::class);
        $sftp->shouldReceive('download')->once()->with($remoteDump, $localSql)
            ->andReturnUsing(function ($remote, $local) {
                file_put_contents($local, "-- SQL --\n");
                return null;
            });

        $executor->sftp = $sftp;

        $cmd = Mockery::mock(DbPullTestHelper::class)->makePartial();
        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnTrue();
        $cmd->shouldReceive('option')->with('no-import')->andReturnTrue();
        $cmd->shouldReceive('createExecutor')->once()->with($server)->andReturn($executor);

        // Import should not be called
        $cmd->shouldNotReceive('runLocalCommand');

        // Console outputs
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('comment')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();

        $result = $cmd->handle();
        $this->assertSame(DbPull::SUCCESS, $result);
        $this->assertFileExists($localSql);
    }

    /**
     * Test function: handle
     * If remote dump fails (exception), the command returns FAILURE and still attempts cleanup.
     */
    public function testHandleRemoteErrorReturnsFailureAndCleansUp(): void
    {
        $server = 'error-srv';
        $remotePath = '/data/app';
        $remoteDumpGz = $remotePath . '/' . $this->timestamp . '.sql.gz';

        $executor = new RemoteExecutorStubForDbPull();
        $executor->config = [
            'path' => $remotePath,
            'database' => [
                'username' => 'ruser',
                'password' => 'rpass',
                'database' => 'rdb',
                'tables'   => ['a'],
            ],
        ];

        $ssh = Mockery::mock(SshExecutor::class);
        // Throw on dump
        $ssh->shouldReceive('runRawCommand')->once()->andThrow(new \RuntimeException('remote dump failed'));

        // Cleanup still attempted (rm -f ...)
        $ssh->shouldReceive('runRawCommand')->once()->with(Mockery::on(function ($cmd) use ($remoteDumpGz) {
            return str_starts_with($cmd, 'rm -f') && str_contains($cmd, basename($remoteDumpGz));
        }))->andReturn('');

        $executor->ssh = $ssh;

        // SFTP should not be called in this path, but safe to provide a mock
        $executor->sftp = Mockery::mock(SftpExecutor::class);

        $cmd = Mockery::mock(DbPullTestHelper::class)->makePartial();
        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull();
        $cmd->shouldReceive('option')->with('no-import')->andReturnNull();
        $cmd->shouldReceive('createExecutor')->once()->with($server)->andReturn($executor);

        // Console outputs
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')->atLeast()->once();

        $result = $cmd->handle();
        $this->assertSame(DbPull::FAILURE, $result);

        // No local files should exist
        $this->assertFileDoesNotExist(base_path($this->timestamp . '.sql'));
        $this->assertFileDoesNotExist(base_path($this->timestamp . '.sql.gz'));
    }
}
