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

        // Stable timestamp
        Carbon::setTestNow(Carbon::create(2024, 1, 2, 3, 4, 5));
    }

    public function tearDown(): void
    {
        // Cleanup any dump files created in cwd
        foreach (['2024-01-02_03_04_05.sql.gz', '2024-01-02_03_04_05.sql'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        // Cleanup any moved files/directories created by tests
        $movedDir = 'local/backups/';
        $movedFile = $movedDir . '2024-01-02_03_04_05.sql.gz';

        if (is_file($movedFile)) {
            @unlink($movedFile);
        }

        if (is_dir($movedDir)) {
            // Attempt to remove directory if empty
            @rmdir($movedDir);
            // Also try removing parent 'local' if empty
            @rmdir('local');
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: handle
     * With no cloud specified, creates gzip dump and returns SUCCESS without moving/deleting.
     */
    public function testHandleCreatesGzipDumpLocally(): void
    {
        $cmd = Mockery::mock(DbPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        // options/args defaults
        $cmd->shouldReceive('option')->with('folder')->andReturnNull();
        $cmd->shouldReceive('option')->with('timestamp')->andReturnNull();
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull(); // gzip enabled
        $cmd->shouldReceive('argument')->with('cloud')->andReturnNull();

        // Expect mysqldump with gzip to the known filename
        $expectedFile = '2024-01-02_03_04_05.sql.gz';
        $cmd->shouldReceive('runLocalCommand')->once()->with(
            "mysqldump -uuser -ppass db | gzip > {$expectedFile}"
        )->andReturnUsing(function () use ($expectedFile) {
            file_put_contents($expectedFile, 'dump');
            return '';
        });

        // Allow console output noise
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('comment')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(DbPush::SUCCESS, $result);
        $this->assertFileExists($expectedFile);
    }

    /**
     * Test function: handle
     * Uploads to cloud then deletes local file by default.
     */
    public function testHandleUploadsToCloudAndDeletesLocal(): void
    {
        $cmd = Mockery::mock(DbPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        // Provide folder option; default gzip
        $cmd->shouldReceive('option')->with('folder')->andReturn('backups');
        $cmd->shouldReceive('option')->with('timestamp')->andReturn('Y-m-d_H_i_s');
        $cmd->shouldReceive('option')->with('no-gzip')->andReturnNull();
        $cmd->shouldReceive('option')->with('no-delete')->andReturnNull();
        $cmd->shouldReceive('argument')->with('cloud')->andReturn('s3');

        $expectedFile = '2024-01-02_03_04_05.sql.gz';
        $expectedKey = 'backups/' . $expectedFile;

        // runLocalCommand creates the file we will upload
        $cmd->shouldReceive('runLocalCommand')->once()->with(
            "mysqldump -uuser -ppass db | gzip > {$expectedFile}"
        )->andReturnUsing(function () use ($expectedFile) {
            file_put_contents($expectedFile, 'dump');
            return '';
        });

        // Mock Storage facade to return a disk mock that receives the upload once
        $cloud = Mockery::mock();
        $cloud->shouldReceive('put')->once()->with($expectedKey, Mockery::type('resource'))->andReturnTrue();
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($cloud);

        // Allow console outputs
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('comment')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(DbPush::SUCCESS, $result);
        $this->assertFileDoesNotExist($expectedFile);
    }

    /**
     * Test function: handle + moveFile
     * Uploads to cloud, keeps local file (no-delete) and moves it into provided folder.
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
        $expectedKey = 'local/backups/' . $expectedFile;

        $cmd->shouldReceive('runLocalCommand')->once()->with(
            "mysqldump -uuser -ppass db | gzip > {$expectedFile}"
        )->andReturnUsing(function () use ($expectedFile) {
            file_put_contents($expectedFile, 'dump');
            return '';
        });

        // Mock cloud upload
        $cloud = Mockery::mock();
        $cloud->shouldReceive('put')->once()->with($expectedKey, Mockery::type('resource'))->andReturnTrue();
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($cloud);

        // Allow console outputs
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('comment')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(DbPush::SUCCESS, $result);
        // Ensure file was moved locally into the folder
        $this->assertFileExists($expectedKey);
        $this->assertFileDoesNotExist($expectedFile);
    }
}
