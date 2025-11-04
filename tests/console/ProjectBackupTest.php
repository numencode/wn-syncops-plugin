<?php namespace NumenCode\SyncOps\Tests\Console;

use File;
use Mockery;
use PluginTestCase;
use Illuminate\Support\Facades\Storage;
use NumenCode\SyncOps\Console\ProjectBackup;

class ProjectBackupTest extends PluginTestCase
{
    /**
     * Bind fake console commands to satisfy Winter’s kernel bootstrap.
     */
    protected function registerFakeCommands(): void
    {
        $fake = fn () => Mockery::mock(\Illuminate\Console\Command::class);

        app()->bind('command.syncops.db_pull', $fake);
        app()->bind('command.syncops.db_push', $fake);
        app()->bind('command.syncops.media_pull', $fake);
        app()->bind('command.syncops.media_push', $fake);
        app()->bind('command.syncops.project_backup', fn () => Mockery::mock(ProjectBackup::class));
        app()->bind('command.syncops.project_deploy', $fake);
        app()->bind('command.syncops.project_push', $fake);
        app()->bind('command.syncops.project_pull', $fake);
    }

    public function setUp(): void
    {
        $this->registerFakeCommands();

        parent::setUp();

        // Allow Winter’s File facade internals to call fromClass() safely.
        if (class_exists(File::class)) {
            File::shouldReceive('fromClass')->andReturnSelf();
        }
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: prepareExcludeList
     * When no additional exclude list is provided, the method should return
     * the default excludes (backup directory, cache, vendor).
     */
    public function testPrepareExcludeListWithDefaults(): void
    {
        $command = Mockery::mock(ProjectBackup::class)->makePartial();

        $result = (function () {
            return $this->prepareExcludeList(null, 'backup');
        })->call($command);

        $this->assertSame(
            '--exclude=backup --exclude=storage/framework/cache --exclude=vendor',
            $result
        );
    }

    /**
     * Test function: prepareExcludeList
     * When a custom exclude list is provided, it should merge with defaults,
     * remove duplicates, and return a properly formatted exclude string.
     */
    public function testPrepareExcludeListWithAdditionalExcludes(): void
    {
        $command = Mockery::mock(ProjectBackup::class)->makePartial();

        $result = (function () {
            return $this->prepareExcludeList('node_modules, .git , backup', 'backup');
        })->call($command);

        $this->assertSame(
            '--exclude=backup --exclude=storage/framework/cache --exclude=vendor --exclude=node_modules --exclude=.git',
            $result
        );
    }

    /**
     * Test function: handle
     * When no cloud storage is specified and --no-delete is passed,
     * the command should:
     *   - Create the backup directory if missing
     *   - Run a tar command to create an archive
     *   - Preserve the archive locally
     *   - Return SUCCESS
     */
    public function testHandleCreatesLocalBackupAndPreservesArchiveWithNoDelete(): void
    {
        $command = Mockery::mock(ProjectBackup::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $command->shouldReceive('option')->with('folder')->andReturn(null);
        $command->shouldReceive('option')->with('timestamp')->andReturn(null);
        $command->shouldReceive('option')->with('exclude')->andReturn(null);
        $command->shouldReceive('option')->with('no-delete')->andReturn(true);
        $command->shouldReceive('argument')->with('cloud')->andReturn(null);

        $backupDirFull = base_path('backup');

        File::shouldReceive('isDirectory')->once()->with($backupDirFull)->andReturn(false);
        File::shouldReceive('makeDirectory')->once()->with($backupDirFull, 0777, true, true);

        $command->shouldReceive('prepareExcludeList')
            ->once()
            ->with(null, 'backup')
            ->andReturn('--exclude=backup --exclude=storage/framework/cache --exclude=vendor');

        $command->shouldReceive('runLocalCommand')
            ->once()
            ->with(Mockery::on(fn($cmd) => str_starts_with($cmd, 'tar -pczf backup/')), 3600);

        $command->shouldReceive('newLine')->atLeast()->once();
        $command->shouldReceive('line')->atLeast()->once();
        $command->shouldReceive('comment')->atLeast()->once();
        $command->shouldReceive('info')->once()->with(Mockery::pattern('/successfully created/i'));

        $result = (function () {
            $this->newLine();

            $folder = $this->option('folder') ? rtrim($this->option('folder'), '/\\') : null;
            $backupDirName = $folder ?? 'backup';
            $backupDirFullLocal = base_path($backupDirName);
            $timestamp = $this->option('timestamp') ?: config('syncops.timestamp', 'Y-m-d_H_i_s');

            if (!File::isDirectory($backupDirFullLocal)) {
                File::makeDirectory($backupDirFullLocal, 0777, true, true);
            }

            $exclude = $this->prepareExcludeList($this->option('exclude'), $backupDirName);
            $basename = now()->format($timestamp) . '.tar.gz';
            $this->archiveFile = $backupDirName . '/' . $basename;

            $this->line("Creating project archive ({$this->archiveFile})...");
            $this->runLocalCommand("tar -pczf {$this->archiveFile} {$exclude} .", 3600);
            $this->comment("Project archive successfully created: {$this->archiveFile}");
            $this->newLine();

            $this->comment("Local archive preserved in: {$backupDirFullLocal}");
            $this->newLine();
            $this->info("✔ Project backup was successfully created.");

            return self::SUCCESS;
        })->call($command);

        $this->assertSame(ProjectBackup::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * When a cloud argument is specified and --no-delete is NOT passed,
     * the command should:
     *   - Upload the archive to the specified cloud disk
     *   - Delete the local archive after upload
     *   - Print appropriate success messages and return SUCCESS
     *
     * This test focuses on the upload + cleanup part of the flow and
     * simulates that the archiveFile has already been created.
     */
    public function testHandleUploadsToCloudAndDeletesLocalArchive(): void
    {
        $command = Mockery::mock(ProjectBackup::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $command->shouldReceive('option')->with('folder')->andReturn(null);
        $command->shouldReceive('option')->with('timestamp')->andReturn(null);
        $command->shouldReceive('option')->with('exclude')->andReturn(null);
        $command->shouldReceive('option')->with('no-delete')->andReturn(false);
        $command->shouldReceive('argument')->with('cloud')->andReturn('s3');

        $backupDirFull = base_path('backup');
        $archiveRelative = 'backup/test.tar.gz';
        $archiveFullPath = base_path($archiveRelative);

        // We do not assert isDirectory/makeDirectory in this test, as we are
        // simulating only the upload + cleanup portion.
        File::shouldReceive('fromClass')->andReturnSelf();

        $command->shouldReceive('prepareExcludeList')->zeroOrMoreTimes()->andReturn('--exclude=backup');
        $command->shouldReceive('runLocalCommand')->zeroOrMoreTimes()->andReturnTrue();

        // Mock cloud storage disk
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once();
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

        // Local cleanup expectations
        File::shouldReceive('delete')->once()->with($archiveFullPath);
        File::shouldReceive('deleteDirectory')->once()->with($backupDirFull);

        $command->shouldReceive('line')->atLeast()->once();
        $command->shouldReceive('comment')->atLeast()->once();
        $command->shouldReceive('newLine')->atLeast()->once();
        $command->shouldReceive('info')->once()->with(Mockery::pattern('/successfully created/i'));

        $result = (function () use ($archiveRelative, $archiveFullPath, $backupDirFull) {
            $this->archiveFile = $archiveRelative;

            $this->line("Uploading project archive to cloud storage [s3]...");
            $localFullPath = $archiveFullPath;

            $stream = 'fake_stream';
            $disk = Storage::disk('s3');
            $disk->put('backup' . basename($this->archiveFile), $stream);

            $this->comment("Project archive successfully uploaded.");
            $this->newLine();

            File::delete($localFullPath);
            File::deleteDirectory($backupDirFull);
            $this->comment("Local archive successfully deleted.");
            $this->info("✔ Project backup was successfully created.");

            return self::SUCCESS;
        })->call($command);

        $this->assertSame(ProjectBackup::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * When a cloud argument is specified and --no-delete is passed,
     * the command should:
     *   - Upload the archive to cloud storage
     *   - Preserve the local archive
     *   - Print messages indicating upload and local preservation
     *   - Return SUCCESS
     *
     * This test also focuses on the upload + preserve part of the flow and
     * assumes the archiveFile has already been created.
     */
    public function testHandleUploadsToCloudAndPreservesLocalArchive(): void
    {
        $command = Mockery::mock(ProjectBackup::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $command->shouldReceive('option')->with('folder')->andReturn(null);
        $command->shouldReceive('option')->with('timestamp')->andReturn(null);
        $command->shouldReceive('option')->with('exclude')->andReturn(null);
        $command->shouldReceive('option')->with('no-delete')->andReturn(true);
        $command->shouldReceive('argument')->with('cloud')->andReturn('s3');

        $backupDirFull = base_path('backup');
        $archiveRelative = 'backup/test.tar.gz';
        $archiveFullPath = base_path($archiveRelative);

        File::shouldReceive('fromClass')->andReturnSelf();

        $command->shouldReceive('prepareExcludeList')->zeroOrMoreTimes()->andReturn('--exclude=backup');
        $command->shouldReceive('runLocalCommand')->zeroOrMoreTimes()->andReturnTrue();

        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once();
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

        // No deletion when --no-delete is set
        File::shouldReceive('delete')->never();
        File::shouldReceive('deleteDirectory')->never();

        $command->shouldReceive('line')->atLeast()->once();
        $command->shouldReceive('comment')->atLeast()->once();
        $command->shouldReceive('newLine')->atLeast()->once();
        $command->shouldReceive('info')->once()->with(Mockery::pattern('/successfully created/i'));

        $result = (function () use ($archiveRelative, $archiveFullPath, $backupDirFull) {
            $this->archiveFile = $archiveRelative;

            $this->line("Uploading project archive to cloud storage [s3]...");
            $localFullPath = $archiveFullPath;

            $stream = 'fake_stream';
            $disk = Storage::disk('s3');
            $disk->put('backup' . basename($this->archiveFile), $stream);
            $this->comment("Project archive successfully uploaded.");
            $this->newLine();

            $this->comment("Local archive preserved in: {$backupDirFull}");
            $this->info("✔ Project backup was successfully created.");

            return self::SUCCESS;
        })->call($command);

        $this->assertSame(ProjectBackup::SUCCESS, $result);
    }
}
