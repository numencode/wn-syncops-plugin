<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MediaPush extends Command
{
    protected $signature = 'syncops:media-push
        {cloud       : The name of the cloud storage disk to upload media files to}
        {--folder=   : The target folder name in the cloud storage (default: "storage")}
        {--l|log     : Show details for each file being processed}
        {--d|dry-run : Simulate the backup without uploading files}';

    protected $description = 'Back up all media files to the specified cloud storage.';

    public function handle(): int
    {
        $this->newLine();

        $cloud = $this->argument('cloud');
        $folder = format_path($this->option('folder') ?: 'storage');

        try {
            $cloudStorage = Storage::disk($cloud);
        } catch (\InvalidArgumentException $e) {
            $this->error("✘ The cloud storage disk '{$cloud}' is not configured. Please check your config/filesystems.php.");
            return self::FAILURE;
        }

        $log = (bool) $this->option('log');
        $dryRun = (bool) $this->option('dry-run');

        try {
            // Filter files: skip dotfiles and anything under /thumb/
            $files = array_filter(Storage::allFiles(), function (string $file): bool {
                return !str_starts_with(basename($file), '.')
                    && stripos($file, '/thumb/') === false;
            });

            $fileCount = count($files);

            if ($fileCount === 0) {
                $this->info('✔ No media files found to upload.');
                return self::SUCCESS;
            }

            if ($dryRun) {
                $this->dryRun($files);
                return self::SUCCESS;
            }

            $this->line("Uploading {$fileCount} media file(s) to cloud storage '{$cloud}'...");

            $bar = null;

            if (!$log) {
                $bar = $this->output->createProgressBar($fileCount);
            }

            foreach ($files as $file) {
                if (!$log && $bar) {
                    $bar->advance();
                }

                $cloudPath = $folder . $file;

                // Skip upload if remote file exists with identical size
                if ($cloudStorage->exists($cloudPath) && $cloudStorage->size($cloudPath) === Storage::size($file)) {
                    if ($log) {
                        $this->line("File already exists: {$file}");
                    }

                    continue;
                }

                $cloudStorage->put($cloudPath, Storage::get($file));

                if ($log) {
                    $this->comment("File successfully uploaded: {$file}");
                }
            }

            if (!$log && $bar) {
                $bar->finish();
                $this->newLine();
            }

            $this->newLine();
            $this->info('✔ All media files have been successfully uploaded.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("✘ An error occurred while uploading media files to '{$cloud}':");
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Simulate an upload by listing which files would be processed.
     *
     * @param string[] $files
     * @return void
     */
    protected function dryRun(array $files): void
    {
        $this->comment("Dry run: The following files (" . count($files) . ') would be uploaded:');

        foreach ($files as $file) {
            $this->line("- {$file}");
        }
    }
}
