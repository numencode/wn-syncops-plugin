<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class MediaPull extends Command
{
    protected $signature = 'syncops:media-pull
        {server             : The name of the remote server}
        {--o|--no-overwrite : Do not overwrite existing local files if they already exist}';

    protected $description = 'Download media files from the remote server via SFTP into local storage.';

    public function handle(): int
    {
        $this->newLine();

        $serverName = $this->argument('server');
        $noOverwrite = (bool) $this->option('no-overwrite');

        try {
            $this->line("Connecting to remote server '{$serverName}'...");

            $executor = $this->createExecutor($serverName);

            if (empty($executor->config['project']['path'])) {
                throw new \RuntimeException(
                    "Project path is not defined for server '{$serverName}' in syncops configuration."
                );
            }

            $remotePath = rtrim($executor->config['project']['path'], '/') . '/storage/app';
            $localPath  = storage_path('app');

            $this->line('Fetching file list from remote server...');
            $files = $executor->sftp->listFilesRecursively($remotePath);

            $this->newLine();

            if (empty($files)) {
                $this->info('✔ No media files found on remote server.');
                return self::SUCCESS;
            }

            $this->line('Downloading ' . count($files) . ' media files...');
            $bar = $this->output?->createProgressBar(count($files));

            foreach ($files as $remoteFile) {
                $relativePath = ltrim(str_replace($remotePath, '', $remoteFile), '/');
                $localFile    = $localPath . '/' . $relativePath;

                // Ensure directory exists
                if (!is_dir(dirname($localFile))) {
                    mkdir(dirname($localFile), 0777, true);
                }

                // Skip if file exists and we shouldn't overwrite
                if ($noOverwrite && file_exists($localFile)) {
                    $bar?->advance();
                    continue;
                }

                // Skip if same size
                if (file_exists($localFile)) {
                    $remoteSize = $executor->sftp->filesizeRemote($remoteFile);
                    $localSize  = filesize($localFile);

                    if ($remoteSize === $localSize) {
                        $bar?->advance();
                        continue;
                    }
                }

                // Download when needed
                $executor->sftp->download($remoteFile, $localFile);
                $bar?->advance();
            }

            $bar?->finish();

            $this->newLine(2);
            $this->info('✔ Media files successfully synced from remote server.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("✘ An error occurred on server '{$serverName}':");
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Factory method to create a RemoteExecutor instance.
     * Separated for easier testing.
     */
    protected function createExecutor(string $server): RemoteExecutor
    {
        return new RemoteExecutor($server);
    }
}
