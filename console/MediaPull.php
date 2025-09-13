<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class MediaPull extends Command
{
    protected $signature = 'syncops:media-pull
        {server         : The name of the remote server}
        {--no-overwrite : Do not overwrite existing local files if they already exist}';

    protected $description = 'Downloads media files from the remote server via SFTP into local storage.';

    public function handle(): int
    {
        $serverName = $this->argument('server');
        $noOverwrite = $this->option('no-overwrite');

        $this->comment("Connecting to remote server '{$serverName}'...");
        $executor = new RemoteExecutor($serverName);

        $remotePath = rtrim($executor->config['path'], '/') . '/storage/app';
        $localPath = storage_path('app');

        $this->line("Fetching file list from remote server...");
        $files = $executor->sftp->listFilesRecursively($remotePath);

        if (empty($files)) {
            $this->warn("No media files found on remote server.");
            return self::SUCCESS;
        }

        $this->line("Downloading " . count($files) . " media files...");
        $bar = $this->output->createProgressBar(count($files));

        foreach ($files as $remoteFile) {
            $relativePath = ltrim(str_replace($remotePath, '', $remoteFile), '/');
            $localFile = $localPath . '/' . $relativePath;

            // Ensure directory exists
            if (!is_dir(dirname($localFile))) {
                mkdir(dirname($localFile), 0777, true);
            }

            // Skip if file exists and we shouldn't overwrite
            if ($noOverwrite && file_exists($localFile)) {
                continue;
            }

            // Skip if same size
            if (file_exists($localFile)) {
                $remoteSize = $executor->sftp->filesizeRemote($remoteFile);
                $localSize = filesize($localFile);

                if ($remoteSize === $localSize) {
                    continue;
                }
            }

            $executor->sftp->download($remoteFile, $localFile);

            $bar->advance();
        }

        $bar->finish();
        $this->info(PHP_EOL . PHP_EOL . "✔ Media files successfully synced from remote server.");

        return self::SUCCESS;
    }
}
