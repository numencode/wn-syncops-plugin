<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use Carbon\Carbon;
use PluginTestCase;
use NumenCode\SyncOps\Console\DbPush;
use Illuminate\Support\Facades\Storage;

class DbPushTest extends PluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Provide minimal DB config the command reads directly
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'username' => 'user',
            'password' => 'pass',
            'database' => 'db',
        ]);

        // Stable timestamp for predictable filenames
        Carbon::setTestNow(Carbon::create(2024, 1, 2, 3, 4, 5));
    }

    public function tearDown(): void
    {
        // Cleanup all possible dump files and folders
        foreach ([
                     '2024-01-02_03_04_05.sql.gz',
                     '2024-01-02_03_04_05.sql',
                     'local/backups/2024-01-02_03_04_05.sql.gz',
                     'local/backups/2024-01-02_03_04_05.sql',
                 ] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        // Cleanup folders if they exist
        foreach (['local/backups', 'local'] as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: handle
     * With no cloud specified and gzip enabled (default),
     * the command should create a .sql.gz dump locally and return SUCCESS
     * without uploading, moving, or deleting the dump file.
     */
    public function testHandleCreatesGzipDumpLocally(): void
    {
        $cmd = Mockery::mock(DbPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        // No folder or timestamp override, gzip enabled, no cloud target.
        $cmd->shouldReceive('option')->with('folder')->andReturnNull();
        $cmd->shouldReceive('option')->with('timestamp')->andReturnNull();
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull(); // gzip enabled
        $cmd->shouldReceive('argument')->with('cloud')->andReturnNull();

        $expectedFile = '2024-01-02_03_04_05.sql.gz';

        // Simulate mysqldump creating the gzip file
        $cmd->shouldReceive('runLocalCommand')
            ->once()
            ->with("mysqldump -uuser -ppass db | gzip > {$expectedFile}")
            ->andReturnUsing(function () use ($expectedFile) {
                file_put_contents($expectedFile, 'dump');
                return '';
            });

        // Allow console output without strict expectations
        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')->zeroOrMoreTimes();

        $result = $cmd->handle();

        $this->assertSame(DbPush::SUCCESS, $result);
        $this->assertFileExists($expectedFile);
    }

    /**
     * Test function: handle
     * When --no-gzip option is provided, the command should create a plain
     * .sql file (no gzip) and return SUCCESS.
     */
    public function testHandleCreatesPlainSqlWhenNoGzipOptionUsed(): void
    {
        $cmd = Mockery::mock(DbPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('option')->with('folder')->andReturnNull();
        $cmd->shouldReceive('option')->with('timestamp')->andReturnNull();
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnTrue(); // disable gzip
        $cmd->shouldReceive('argument')->with('cloud')->andReturnNull();

        $expectedFile = '2024-01-02_03_04_05.sql';

        $cmd->shouldReceive('runLocalCommand')
            ->once()
            ->with("mysqldump -uuser -ppass db > {$expectedFile}")
            ->andReturnUsing(function () use ($expectedFile) {
                file_put_contents($expectedFile, 'plain dump');
                return '';
            });

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')->zeroOrMoreTimes();

        $result = $cmd->handle();

        $this->assertSame(DbPush::SUCCESS, $result);
        $this->assertFileExists($expectedFile);
    }

    /**
     * Test function: handle
     * When a cloud disk is provided and --no-delete is not set,
     * the command should upload the .sql.gz file to the cloud
     * and delete the local dump file afterwards.
     */
    public function testHandleUploadsToCloudAndDeletesLocal(): void
    {
        $cmd = Mockery::mock(DbPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('option')->with('folder')->andReturn('backups/');
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull();
        $cmd->shouldReceive('option')->with('no-delete')->andReturnNull();
        $cmd->shouldReceive('argument')->with('cloud')->andReturn('s3');

        $expectedFile = '2024-01-02_03_04_05.sql.gz';
        $expectedKey = 'backups/2024-01-02_03_04_05.sql.gz';

        $cmd->shouldReceive('runLocalCommand')
            ->once()
            ->with("mysqldump -uuser -ppass db | gzip > {$expectedFile}")
            ->andReturnUsing(function () use ($expectedFile) {
                file_put_contents($expectedFile, 'dump');
                return '';
            });

        // Mock cloud storage disk and expect the upload
        $cloud = Mockery::mock();
        $cloud->shouldReceive('put')
            ->once()
            ->with($expectedKey, Mockery::type('resource'))
            ->andReturnTrue();

        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($cloud);

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')->zeroOrMoreTimes();

        $result = $cmd->handle();

        $this->assertSame(DbPush::SUCCESS, $result);
        $this->assertFileDoesNotExist($expectedFile);
    }

    /**
     * Test function: handle (+ moveFile)
     * When a cloud disk is provided with --no-delete and a folder option,
     * the command should upload the dump to the cloud AND keep the local file,
     * moving it into the configured folder.
     */
    public function testHandleUploadsWithoutDeleteThenMovesIntoFolder(): void
    {
        $cmd = Mockery::mock(DbPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('option')->with('folder')->andReturn('local/backups/');
        $cmd->shouldReceive('option')->with('timestamp')->andReturnNull();
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull();
        $cmd->shouldReceive('option')->with('no-delete')->andReturnTrue();
        $cmd->shouldReceive('argument')->with('cloud')->andReturn('s3');

        $expectedFile = '2024-01-02_03_04_05.sql.gz';
        $expectedKey = 'local/backups/2024-01-02_03_04_05.sql.gz';

        $cmd->shouldReceive('runLocalCommand')
            ->once()
            ->with("mysqldump -uuser -ppass db | gzip > {$expectedFile}")
            ->andReturnUsing(function () use ($expectedFile) {
                file_put_contents($expectedFile, 'dump');
                return '';
            });

        $cloud = Mockery::mock();
        $cloud->shouldReceive('put')
            ->once()
            ->with($expectedKey, Mockery::type('resource'))
            ->andReturnTrue();

        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($cloud);

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')->zeroOrMoreTimes();

        $result = $cmd->handle();

        $this->assertSame(DbPush::SUCCESS, $result);
        // File should exist in the target folder and not in the root
        $this->assertFileExists($expectedKey);
        $this->assertFileDoesNotExist($expectedFile);
    }
}
