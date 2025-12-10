<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use PluginTestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Storage;
use NumenCode\SyncOps\Console\MediaPush;
use NumenCode\SyncOps\Console\ProjectPull;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Helper\ProgressBar;

class MediaPushTest extends PluginTestCase
{
    /**
     * Bind fake console commands to satisfy Winter’s kernel bootstrap.
     */
    protected static function bindFakeCommands(): void
    {
        $fake = fn() => Mockery::mock(\Illuminate\Console\Command::class);

        app()->instance('command.syncops.db_pull', $fake());
        app()->instance('command.syncops.db_push', $fake());
        app()->instance('command.syncops.media_pull', $fake());
        app()->instance('command.syncops.media_push', $fake());
        app()->instance('command.syncops.project_backup', $fake());
        app()->instance('command.syncops.project_deploy', $fake());
        app()->instance('command.syncops.project_push', $fake());
        app()->instance('command.syncops.project_pull', Mockery::mock(ProjectPull::class));
    }

    /**
     * Ensure fake commands are bound before PluginTestCase bootstraps Artisan.
     */
    public static function setUpBeforeClass(): void
    {
        if (function_exists('app')) {
            self::bindFakeCommands();
        }

        parent::setUpBeforeClass();
    }

    public function setUp(): void
    {
        if (function_exists('app')) {
            self::bindFakeCommands();
        }

        parent::setUp();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper: attach a mocked OutputStyle that returns a real ProgressBar.
     */
    protected function attachOutputMock(MediaPush $command, ?int $expectedCount = null): void
    {
        $output = Mockery::mock(OutputStyle::class);

        $output->shouldReceive('createProgressBar')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (int $max) {
                return new ProgressBar(new NullOutput(), $max);
            });

        $ref = new \ReflectionClass($command);
        $prop = $ref->getProperty('output');
        $prop->setAccessible(true);
        $prop->setValue($command, $output);
    }

    /**
     * Test function: handle
     * If the requested cloud disk is not configured, the command should print
     * an error explaining the problem and return FAILURE without attempting uploads.
     */
    public function testHandleFailsWhenCloudDiskIsNotConfigured(): void
    {
        $cmd = Mockery::mock(MediaPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('cloud')->andReturn('missing_disk');
        $cmd->shouldReceive('option')->with('folder')->andReturnNull();
        $cmd->shouldReceive('option')->with('dry-run')->zeroOrMoreTimes()->andReturnFalse();
        $cmd->shouldReceive('option')->with('log')->zeroOrMoreTimes()->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('error')->once()->with(Mockery::pattern('/not configured/i'));

        Storage::shouldReceive('disk')
            ->once()
            ->with('missing_disk')
            ->andThrow(new \InvalidArgumentException('Disk [missing_disk] does not exist.'));

        $this->attachOutputMock($cmd);

        $result = $cmd->handle();

        $this->assertSame(MediaPush::FAILURE, $result);
    }

    /**
     * Test function: handle
     * When no media files are found (after filtering hidden and thumb files),
     * the command should print an informational message and return SUCCESS
     * without attempting any upload.
     */
    public function testHandleNoMediaFilesReturnsSuccess(): void
    {
        $cmd = Mockery::mock(MediaPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('cloud')->andReturn('s3');
        $cmd->shouldReceive('option')->with('folder')->andReturnNull();
        $cmd->shouldReceive('option')->with('dry-run')->andReturnFalse();
        $cmd->shouldReceive('option')->with('log')->zeroOrMoreTimes()->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('info')->once()->with(Mockery::pattern('/No media files found/i'));

        $disk = Mockery::mock();
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);
        Storage::shouldReceive('allFiles')->once()->andReturn([]);

        $this->attachOutputMock($cmd);

        $result = $cmd->handle();

        $this->assertSame(MediaPush::SUCCESS, $result);
    }

    /**
     * Test function: handle + dryRun
     * When --dry-run is specified, the command should list the filtered files
     * that would be uploaded, print them via comment/line, and return SUCCESS.
     */
    public function testHandleDryRunListsFilteredFilesAndDoesNotUpload(): void
    {
        $cmd = Mockery::mock(MediaPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('cloud')->andReturn('s3');
        $cmd->shouldReceive('option')->with('folder')->andReturnNull();
        $cmd->shouldReceive('option')->with('dry-run')->andReturnTrue();
        $cmd->shouldReceive('option')->with('log')->zeroOrMoreTimes()->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('comment')->once()->with(Mockery::pattern('/Dry run.*2/i'));
        $cmd->shouldReceive('line')->once()->with('- media/file1.jpg');
        $cmd->shouldReceive('line')->once()->with('- media/file2.png');

        $disk = Mockery::mock();
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

        Storage::shouldReceive('allFiles')->once()->andReturn([
            '.DS_Store',
            'media/file1.jpg',
            'media/file2.png',
            'media/thumb/thumb.jpg',
        ]);

        $this->attachOutputMock($cmd);

        $result = $cmd->handle();

        $this->assertSame(MediaPush::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * When files exist and --dry-run/--log are not used, the command should:
     *  - show a progress bar,
     *  - upload each filtered file to the cloud,
     *  - and return SUCCESS.
     */
    public function testHandleUploadsAllFilesWithProgressBarWhenNoLog(): void
    {
        $cmd = Mockery::mock(MediaPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('cloud')->andReturn('s3');
        $cmd->shouldReceive('option')->with('folder')->andReturnNull();
        $cmd->shouldReceive('option')->with('dry-run')->andReturnFalse();
        $cmd->shouldReceive('option')->with('log')->zeroOrMoreTimes()->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')->once()->with(Mockery::pattern('/successfully uploaded/i'));

        $files = ['media/file1.jpg', 'media/file2.png'];

        $cloudDisk = Mockery::mock();
        $cloudDisk->shouldReceive('exists')->twice()->andReturnFalse();
        $cloudDisk->shouldReceive('put')->twice()->andReturnTrue();

        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($cloudDisk);
        Storage::shouldReceive('allFiles')->once()->andReturn($files);
        Storage::shouldReceive('get')->twice()->andReturn('content');

        $this->attachOutputMock($cmd, count($files));

        $result = $cmd->handle();

        $this->assertSame(MediaPush::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * In verbose --log mode, the command should:
     *  - skip files that already exist in the cloud with the same size,
     *  - upload files that are missing or have differing size,
     *  - and print per-file log messages instead of a progress bar.
     */
    public function testHandleWithLogSkipsExistingSameSizeAndUploadsMissing(): void
    {
        $cmd = Mockery::mock(MediaPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('cloud')->andReturn('s3');
        $cmd->shouldReceive('option')->with('folder')->andReturnNull();
        $cmd->shouldReceive('option')->with('dry-run')->andReturnFalse();
        $cmd->shouldReceive('option')->with('log')->zeroOrMoreTimes()->andReturnTrue();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('comment')->atLeast()->once();
        $cmd->shouldReceive('info')->once()->with(Mockery::pattern('/successfully uploaded/i'));

        $files = ['media/file1.jpg', 'media/file2.png'];

        $cloudDisk = Mockery::mock();
        $cloudDisk->shouldReceive('exists')->andReturn(true, false);
        $cloudDisk->shouldReceive('size')->once()->andReturn(123);
        $cloudDisk->shouldReceive('put')->once()->andReturnTrue();

        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($cloudDisk);
        Storage::shouldReceive('allFiles')->once()->andReturn($files);
        Storage::shouldReceive('size')->once()->andReturn(123);
        Storage::shouldReceive('get')->once()->andReturn('file2data');

        $this->attachOutputMock($cmd);

        $result = $cmd->handle();

        $this->assertSame(MediaPush::SUCCESS, $result);
    }
}
