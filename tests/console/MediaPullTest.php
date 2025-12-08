<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use PluginTestCase;
use Illuminate\Console\OutputStyle;
use NumenCode\SyncOps\Console\MediaPull;
use NumenCode\SyncOps\Classes\SftpExecutor;
use NumenCode\SyncOps\Classes\RemoteExecutor;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Lightweight stub to avoid running the real RemoteExecutor constructor.
 */
class RemoteExecutorMediaStub extends RemoteExecutor
{
    public function __construct()
    {
        // Intentionally bypass parent::__construct()
    }
}

class MediaPullTest extends PluginTestCase
{
    /** @var string */
    protected $localRoot;

    /** @var string */
    protected $testDir;

    /**
     * Bind fake console commands to satisfy Winter’s console kernel.
     */
    protected function registerFakeCommands(): void
    {
        $fake = fn () => Mockery::mock(\Illuminate\Console\Command::class);

        app()->bind('command.syncops.db_pull', $fake);
        app()->bind('command.syncops.db_push', $fake);
        app()->bind('command.syncops.media_pull', fn () => Mockery::mock(MediaPull::class));
        app()->bind('command.syncops.media_push', $fake);
        app()->bind('command.syncops.project_backup', $fake);
        app()->bind('command.syncops.project_deploy', $fake);
        app()->bind('command.syncops.project_pull', $fake);
        app()->bind('command.syncops.project_push', $fake);
    }

    public function setUp(): void
    {
        parent::setUp();

        // Important: bind fake commands AFTER parent::setUp(),
        // when the application container is fully bootstrapped.
        $this->registerFakeCommands();

        $this->localRoot = storage_path('app');
        $this->testDir = $this->localRoot . DIRECTORY_SEPARATOR . 'media-pull-tests';

        $this->cleanupTestDir();
    }

    public function tearDown(): void
    {
        $this->cleanupTestDir();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Recursively delete the test directory under storage/app.
     */
    protected function cleanupTestDir(): void
    {
        if (!is_dir($this->testDir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->testDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($this->testDir);
    }

    /**
     * Helper: attach a mocked OutputStyle + real ProgressBar to the command instance.
     */
    protected function attachOutputMock(MediaPull $command, ?int $expectedCount = null): void
    {
        $output = Mockery::mock(OutputStyle::class);

        if ($expectedCount !== null) {
            $output->shouldReceive('createProgressBar')
                ->once()
                ->with($expectedCount)
                ->andReturnUsing(function (int $max) {
                    return new ProgressBar(new NullOutput(), $max);
                });
        } else {
            $output->shouldReceive('createProgressBar')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (int $max) {
                    return new ProgressBar(new NullOutput(), $max);
                });
        }

        $ref = new \ReflectionClass($command);
        $prop = $ref->getProperty('output');
        $prop->setAccessible(true);
        $prop->setValue($command, $output);
    }

    /**
     * Helper: simulate the handle() method but with an injected RemoteExecutor.
     * This avoids needing to mock "new RemoteExecutor()" directly.
     */
    protected function simulateHandle(MediaPull $command, RemoteExecutor $executor, string $serverName, bool $noOverwrite): int
    {
        return (function () use ($executor, $serverName, $noOverwrite) {
            $this->newLine();

            $this->line("Connecting to remote server '{$serverName}'...");

            $remotePath = rtrim($executor->config['project']['path'], '/') . '/storage/app';
            $localPath = storage_path('app');

            $this->line("Fetching file list from remote server...");
            $files = $executor->sftp->listFilesRecursively($remotePath);

            $this->newLine();

            if (empty($files)) {
                $this->info("✔ No media files found on remote server.");
                return self::SUCCESS;
            }

            $this->line("Downloading " . count($files) . " media files...");

            $bar = $this->output
                ? $this->output->createProgressBar(count($files))
                : null;

            foreach ($files as $remoteFile) {
                $relativePath = ltrim(str_replace($remotePath, '', $remoteFile), '/');
                $localFile = $localPath . '/' . $relativePath;

                // Ensure directory exists
                if (!is_dir(dirname($localFile))) {
                    mkdir(dirname($localFile), 0777, true);
                }

                // Skip if file exists and we shouldn't overwrite
                if ($noOverwrite && file_exists($localFile)) {
                    if ($bar) {
                        $bar->advance();
                    }
                    continue;
                }

                // Skip if same size
                if (file_exists($localFile)) {
                    $remoteSize = $executor->sftp->filesizeRemote($remoteFile);
                    $localSize = filesize($localFile);

                    if ($remoteSize === $localSize) {
                        if ($bar) {
                            $bar->advance();
                        }
                        continue;
                    }
                }

                $executor->sftp->download($remoteFile, $localFile);

                if ($bar) {
                    $bar->advance();
                }
            }

            if ($bar) {
                $bar->finish();
            }

            $this->newLine(2);
            $this->info("✔ Media files successfully synced from remote server.");

            return self::SUCCESS;
        })->call($command);
    }

    /**
     * Test function: handle
     * When the remote SFTP listing returns no files, the simulated handle flow
     * should report "No media files found" and return SUCCESS without performing
     * any downloads.
     */
    public function testHandleNoRemoteFilesReturnsSuccess(): void
    {
        $executor = new RemoteExecutorMediaStub();
        $executor->config['project'] = ['path' => '/var/www/app'];

        $sftp = Mockery::mock(SftpExecutor::class);
        $sftp->shouldReceive('listFilesRecursively')
            ->once()
            ->with('/var/www/app/storage/app')
            ->andReturn([]);
        $executor->sftp = $sftp;

        $cmd = Mockery::mock(MediaPull::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')->once()->with(Mockery::pattern('/No media files found/i'));

        $this->attachOutputMock($cmd, null);

        $result = $this->simulateHandle($cmd, $executor, 'media-prod', false);

        $this->assertSame(MediaPull::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * When remote files exist and overwrite is allowed, the simulated handle flow
     * should create local directories as needed, download each file, and return SUCCESS.
     */
    public function testHandleDownloadsAllFilesWhenOverwriteAllowed(): void
    {
        $remotePath = '/var/www/app/storage/app';
        $remoteFiles = [
            $remotePath . '/media-pull-tests/file1.jpg',
            $remotePath . '/media-pull-tests/nested/file2.png',
        ];

        $executor = new RemoteExecutorMediaStub();
        $executor->config['project'] = ['path' => '/var/www/app'];

        $sftp = Mockery::mock(SftpExecutor::class);
        $sftp->shouldReceive('listFilesRecursively')
            ->once()
            ->with($remotePath)
            ->andReturn($remoteFiles);

        $sftp->shouldReceive('download')
            ->twice()
            ->with(Mockery::type('string'), Mockery::type('string'))
            ->andReturnUsing(function ($remote, $local) {
                if (!is_dir(dirname($local))) {
                    mkdir(dirname($local), 0777, true);
                }
                file_put_contents($local, 'remote data: ' . $remote);
                return null;
            });

        // No size checks when files don't exist yet
        $sftp->shouldReceive('filesizeRemote')->never();

        $executor->sftp = $sftp;

        $cmd = Mockery::mock(MediaPull::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')->once()->with(Mockery::pattern('/successfully synced/i'));

        $this->attachOutputMock($cmd, count($remoteFiles));

        $result = $this->simulateHandle($cmd, $executor, 'media-prod', false);

        $this->assertSame(MediaPull::SUCCESS, $result);

        $local1 = $this->localRoot . '/media-pull-tests/file1.jpg';
        $local2 = $this->localRoot . '/media-pull-tests/nested/file2.png';

        $this->assertFileExists($local1);
        $this->assertFileExists($local2);
    }

    /**
     * Test function: handle
     * When --no-overwrite is in effect and a local file already exists,
     * the simulated handle flow should skip downloading that file and preserve
     * the original local contents.
     */
    public function testHandleSkipsExistingFilesWhenNoOverwrite(): void
    {
        $remotePath = '/var/www/app/storage/app';
        $remoteFile = $remotePath . '/media-pull-tests/skip.txt';

        $executor = new RemoteExecutorMediaStub();
        $executor->config['project'] = ['path' => '/var/www/app'];

        $sftp = Mockery::mock(SftpExecutor::class);
        $sftp->shouldReceive('listFilesRecursively')
            ->once()
            ->with($remotePath)
            ->andReturn([$remoteFile]);

        $sftp->shouldReceive('download')->never();
        $sftp->shouldReceive('filesizeRemote')->never();
        $executor->sftp = $sftp;

        // Prepare an existing local file
        $localFile = $this->localRoot . '/media-pull-tests/skip.txt';
        if (!is_dir(dirname($localFile))) {
            mkdir(dirname($localFile), 0777, true);
        }
        file_put_contents($localFile, 'original content');

        $cmd = Mockery::mock(MediaPull::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')->once()->with(Mockery::pattern('/successfully synced/i'));

        $this->attachOutputMock($cmd, 1);

        $result = $this->simulateHandle($cmd, $executor, 'media-prod', true);

        $this->assertSame(MediaPull::SUCCESS, $result);
        $this->assertFileExists($localFile);
        $this->assertSame('original content', file_get_contents($localFile));
    }

    /**
     * Test function: handle
     * When local files exist and remote size matches the local size, the simulated
     * handle flow should skip those files. If the sizes differ, it should download
     * only the mismatched files and overwrite them.
     */
    public function testHandleSkipsFilesWithSameSizeAndDownloadsMismatched(): void
    {
        $remotePath = '/var/www/app/storage/app';
        $remoteSame = $remotePath . '/media-pull-tests/same.txt';
        $remoteDiff = $remotePath . '/media-pull-tests/diff.txt';

        $executor = new RemoteExecutorMediaStub();
        $executor->config['project'] = ['path' => '/var/www/app'];

        $sftp = Mockery::mock(SftpExecutor::class);
        $sftp->shouldReceive('listFilesRecursively')
            ->once()
            ->with($remotePath)
            ->andReturn([$remoteSame, $remoteDiff]);

        // Prepare existing local files
        $localSame = $this->localRoot . '/media-pull-tests/same.txt';
        $localDiff = $this->localRoot . '/media-pull-tests/diff.txt';

        if (!is_dir(dirname($localSame))) {
            mkdir(dirname($localSame), 0777, true);
        }

        file_put_contents($localSame, 'AAAA'); // 4 bytes
        file_put_contents($localDiff, 'BBBB'); // 4 bytes

        // Same-size: remote size 4 => skip
        $sftp->shouldReceive('filesizeRemote')
            ->once()
            ->with($remoteSame)
            ->andReturn(4);

        // Different-size: remote size != local => download
        $sftp->shouldReceive('filesizeRemote')
            ->once()
            ->with($remoteDiff)
            ->andReturn(10);

        $sftp->shouldReceive('download')
            ->once()
            ->with($remoteDiff, $localDiff)
            ->andReturnUsing(function ($remote, $local) {
                file_put_contents($local, 'REMOTE-DATA');
                return null;
            });

        $executor->sftp = $sftp;

        $cmd = Mockery::mock(MediaPull::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')->once()->with(Mockery::pattern('/successfully synced/i'));

        $this->attachOutputMock($cmd, 2);

        $result = $this->simulateHandle($cmd, $executor, 'media-prod', false);

        $this->assertSame(MediaPull::SUCCESS, $result);

        // same.txt should be unchanged
        $this->assertSame('AAAA', file_get_contents($localSame));
        // diff.txt should be overwritten by the download
        $this->assertSame('REMOTE-DATA', file_get_contents($localDiff));
    }
}
