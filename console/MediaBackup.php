<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use Illuminate\Support\Facades\File;
use League\Flysystem\FileAttributes;
use NumenCode\SyncOps\Classes\SyncOpsCommand;
use Throwable;

class MediaBackup extends SyncOpsCommand
{
    protected $signature = 'syncops:media-backup
        {cloud          : Storage disk to upload to (e.g. "dropbox")}
        {--folder=storage : Folder on the cloud disk}
        {--l|log        : List every uploaded file}
        {--d|dry-run    : Only list the files that would be uploaded}';

    protected $description = 'Back up the files in storage/app (media, uploads) to cloud storage; unchanged files are skipped.';

    protected function process(): int
    {
        $cloud = (string) $this->argument('cloud');
        $folder = trim((string) $this->option('folder'), '/');
        $this->cloudDisk($cloud); // fail early when the disk is not configured
        $localRoot = storage_path('app');

        $local = [];

        foreach (File::allFiles($localRoot, true) as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());

            if (!$this->isExcludedMedia($path)) {
                $local[$path] = $file->getSize();
            }
        }

        $existing = $this->step("Listing the files on '{$cloud}'", fn () => $this->cloudFiles($cloud, $folder));
        $upload = array_filter($local, static fn (int $size, string $path) => ($existing[$path] ?? null) !== $size, ARRAY_FILTER_USE_BOTH);
        $bytes = array_sum($upload);
        $skipped = count($local) - count($upload);

        $this->line(count($upload) . ' file(s) to upload (' . $this->bytes($bytes) . "), {$skipped} unchanged.");

        if ($this->option('dry-run')) {
            foreach ($upload as $path => $size) {
                $this->line("  {$path} (" . $this->bytes($size) . ')');
            }

            $this->info('✔ Dry run: nothing was uploaded.');

            return self::SUCCESS;
        }

        $bar = $this->option('log') || $upload === [] ? null : $this->output->createProgressBar(count($upload));

        foreach ($upload as $path => $size) {
            $this->uploadFile($cloud, $localRoot . '/' . $path, ($folder !== '' ? $folder . '/' : '') . $path);
            $bar?->advance();

            if ($this->option('log')) {
                $this->line("  Uploaded {$path} (" . $this->bytes($size) . ')');
            }
        }

        if ($bar !== null) {
            $bar->finish();
            $this->newLine(2);
        }

        $this->info('✔ Uploaded ' . count($upload) . ' file(s) (' . $this->bytes($bytes) . ") to '{$cloud}', skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * All files below a cloud folder with their sizes, from one recursive listing.
     *
     * @return array<string, int> path relative to the folder => size
     */
    protected function cloudFiles(string $cloud, string $folder): array
    {
        $files = [];
        $prefix = $folder !== '' ? $folder . '/' : '';

        try {
            $listing = $this->cloudDisk($cloud)->getDriver()->listContents($folder, true);

            foreach ($listing as $item) {
                if ($item instanceof FileAttributes) {
                    $path = ltrim(str_replace('\\', '/', $item->path()), '/');
                    $relative = $prefix !== '' && str_starts_with(strtolower($path), strtolower($prefix)) ? substr($path, strlen($prefix)) : $path;
                    $files[$relative] = (int) $item->fileSize();
                }
            }
        } catch (Throwable $e) {
            // The folder doesn't exist yet: everything is uploaded
            $this->comment("  (no existing files found in '{$folder}')");
        }

        return $files;
    }
}
