<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use Illuminate\Support\Facades\File;
use NumenCode\SyncOps\Classes\SyncOpsCommand;

class MediaPull extends SyncOpsCommand
{
    protected $signature = 'syncops:media-pull
        {server            : The connection name from config/syncops.php}
        {--o|no-overwrite  : Never overwrite existing local files}
        {--dry-run         : Only list the files that would be downloaded}';

    protected $description = 'Download the files in storage/app (media, uploads) from a server; unchanged files are skipped.';

    protected function process(): int
    {
        $name = (string) $this->argument('server');
        $server = $this->server($name);
        $remoteRoot = $server->projectPath('storage/app');
        $localRoot = storage_path('app');

        $remoteFiles = $this->step('Listing the files on the server', static fn () => $server->listFiles($remoteRoot));
        $download = [];
        $skipped = 0;

        foreach ($remoteFiles as $path => $size) {
            if ($this->isExcludedMedia($path)) {
                continue;
            }

            $local = $localRoot . '/' . $path;

            if (is_file($local) && ($this->option('no-overwrite') || filesize($local) === $size)) {
                $skipped++;
                continue;
            }

            $download[$path] = $size;
        }

        $bytes = array_sum($download);
        $this->line(count($download) . ' file(s) to download (' . $this->bytes($bytes) . "), {$skipped} unchanged.");

        if ($this->option('dry-run')) {
            foreach ($download as $path => $size) {
                $this->line("  {$path} (" . $this->bytes($size) . ')');
            }

            $this->info('✔ Dry run: nothing was downloaded.');

            return self::SUCCESS;
        }

        if ($download !== []) {
            $bar = $this->output->createProgressBar(count($download));

            foreach ($download as $path => $size) {
                $local = $localRoot . '/' . $path;

                if (!File::isDirectory(dirname($local))) {
                    File::makeDirectory(dirname($local), 0755, true, true);
                }

                $server->download($remoteRoot . '/' . $path, $local);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        }

        $this->info('✔ Downloaded ' . count($download) . ' file(s) (' . $this->bytes($bytes) . "), skipped {$skipped}.");

        return self::SUCCESS;
    }
}
