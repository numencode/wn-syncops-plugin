<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MediaBackupCommand extends Command
{
    protected $signature = 'syncops:media-backup
        {cloud       : The name of the cloud storage disk to upload media files to}
        {folder?     : The target folder name in the cloud storage (default: "storage")}
        {--l|log     : Show details for each file being processed}
        {--d|dry-run : Simulate the backup without uploading files}';

    protected $description = 'Backs up all media files to the specified cloud storage.';

    public function handle()
    {
        $cloud = $this->argument('cloud');
        $folder = $this->argument('folder');

        try {
            $cloudStorage = Storage::disk($cloud);
        } catch (\InvalidArgumentException $e) {
            $this->error("Error: The cloud storage disk '{$cloud}' is not configured. Please check your config/filesystems.php.");
            return;
        }

        $files = array_filter(Storage::allFiles(), function ($file) {
            return !str_starts_with(basename($file), '.') && stripos($file, '/thumb/') === false;
        });

        $fileCount = count($files);

        if ($fileCount === 0) {
            $this->warn('No media files found to upload.');
            return;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($files);
        }

        $this->line(PHP_EOL . "Uploading {$fileCount} media file(s) to cloud storage \"{$cloud}\"..." . PHP_EOL);

        $bar = $this->output->createProgressBar($fileCount);

        foreach ($files as $file) {
            if (!$this->option('log')) {
                $bar->advance();
            }

            $cloudPath = $this->resolveFolderName($folder) . $file;

            if ($cloudStorage->exists($cloudPath) && $cloudStorage->size($cloudPath) === Storage::size($file)) {
                if ($this->option('log')) {
                    $this->warn("File already exists: {$file}");
                }

                continue;
            }

            $cloudStorage->put($cloudPath, Storage::get($file));

            if ($this->option('log')) {
                $this->info("File successfully uploaded: {$file}");
            }
        }

        $bar->finish();

        $this->info(PHP_EOL . '✔ All media files have been successfully uploaded.');
    }

    protected function resolveFolderName(?string $folderName): string
    {
        return $folderName ? rtrim($folderName, '/') . '/' : 'storage/';
    }

    protected function dryRun(array $files)
    {
        $this->info('Dry run: The following files (' . count($files) . ') would be uploaded:');

        foreach ($files as $file) {
            $this->line("- " . $file);
        }
    }
}
