<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use NumenCode\SyncOps\Traits\RunsLocalCommands;

class ProjectBackup extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:project-backup
        {cloudName?      : Cloud storage where the archive will be uploaded}
        {--folder=       : The folder where the archive will be stored (locally and/or on cloud storage)}
        {--timestamp=    : Date format used for naming the archive file (default: Y-m-d_H-i-s)}
        {--exclude=      : Comma-separated list of folders to exclude (vendor and backup dir are excluded by default)}
        {--d|no-delete   : Do not delete the archive after upload to cloud storage}';

    protected $description = 'Create a compressed archive of project files and optionally upload it to cloud storage.';

    protected string $archiveFile;

    public function handle(): int
    {
        $folder = $this->option('folder') ? rtrim($this->option('folder'), '/\\') : null;
        $backupDirName = $folder ?? 'backup';
        $cloudFolderPrefix = $this->resolveFolderName($backupDirName);
        $backupDirFull = base_path($backupDirName);
        $timestamp = $this->option('timestamp') ?: 'Y-m-d_H-i-s';

        if (!File::isDirectory($backupDirFull)) {
            File::makeDirectory($backupDirFull, 0777, true, true);
        }

        $exclude = $this->prepareExcludeList($this->option('exclude'), $backupDirName);
        $basename = now()->format($timestamp) . '.tar.gz';
        $this->archiveFile = $backupDirName . '/' . $basename;

        $this->line(PHP_EOL . "Creating project archive ({$this->archiveFile})...");
        $this->runLocalCommand("tar -pczf {$this->archiveFile} {$exclude} .", 3600);
        $this->info("Project archive successfully created: {$this->archiveFile}" . PHP_EOL);

        if ($this->argument('cloudName')) {
            $cloudName = $this->argument('cloudName');
            $cloudStorage = Storage::disk($cloudName);

            $this->line("Uploading project archive to cloud storage [{$cloudName}]...");
            $localFullPath = base_path($this->archiveFile);
            $stream = fopen($localFullPath, 'r');
            $cloudStorage->put($cloudFolderPrefix . basename($this->archiveFile), $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $this->info("Project archive successfully uploaded." . PHP_EOL);

            if (!$this->option('no-delete')) {
                $this->line("Deleting local project archive...");
                File::delete($localFullPath);
                $this->info("Local archive successfully deleted." . PHP_EOL);
            } else {
                // Archive is already in $backupDirName
                $this->info("Local archive preserved in: {$backupDirFull}" . PHP_EOL);
            }
        } else {
            // No cloud; if user wants to keep the archive and specified a folder, it's already in that folder.
            if ($this->option('no-delete')) {
                $this->info("Local archive preserved in: {$backupDirFull}" . PHP_EOL);
            }
        }

        $this->info("✔ Project backup was successfully created.");
        return self::SUCCESS;
    }

    protected function resolveFolderName(?string $folderName): ?string
    {
        return $folderName ? rtrim($folderName, '/') . '/' : null;
    }

    protected function prepareExcludeList(?string $excludeList, string $backupDirName): string
    {
        $defaults = [$backupDirName, 'storage/framework/cache', 'vendor'];

        if ($excludeList) {
            $additional = array_map('trim', explode(',', $excludeList));
            $defaults = array_merge($defaults, $additional);
        }

        $defaults = array_unique($defaults);

        return collect($defaults)
            ->filter()
            ->map(fn($item) => '--exclude=' . $item)
            ->implode(' ');
    }
}
