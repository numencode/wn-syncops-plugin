<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use NumenCode\SyncOps\Traits\RunsLocalCommands;

class ProjectBackup extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:project-backup
        {cloud?        : Cloud storage where the archive will be uploaded}
        {--folder=     : The folder where the archive will be stored (default is "backup"; locally and/or on cloud storage)}
        {--timestamp=  : Date format used for naming the archive file}
        {--exclude=    : Comma-separated list of folders to exclude ("vendor" and "backup" dir are excluded by default)}
        {--d|no-delete : Do not delete the archive after upload to cloud storage}';

    protected $description = 'Create a compressed archive of project files and optionally upload it to cloud storage.';

    protected string $archiveFile;

    public function handle(): int
    {
        $this->newLine();

        $folder = $this->option('folder') ? rtrim($this->option('folder'), '/\\') : null;
        $backupDirName = $folder ?? 'backup';
        $cloudFolderPrefix = format_path($backupDirName);
        $backupDirFull = base_path($backupDirName);
        $timestamp = $this->option('timestamp') ?: config('syncops.timestamp', 'Y-m-d_H_i_s');
        $isBackupDirCreated = false;

        if (!File::isDirectory($backupDirFull)) {
            File::makeDirectory($backupDirFull, 0777, true, true);
            $isBackupDirCreated = true;
        }

        $exclude = $this->prepareExcludeList($this->option('exclude'), $backupDirName);
        $basename = now()->format($timestamp) . '.tar.gz';
        $this->archiveFile = $backupDirName . '/' . $basename;

        $this->line("Creating project archive ({$this->archiveFile})...");
        $archiveArg = escapeshellarg($this->archiveFile);
        $tarCommand = "tar -pczf {$archiveArg} {$exclude} .";
        $this->runLocalCommand($tarCommand, 3600);
        $this->comment("Project archive successfully created: {$this->archiveFile}");
        $this->newLine();

        if ($this->argument('cloud')) {
            $cloud = $this->argument('cloud');
            $cloudStorage = Storage::disk($cloud);

            $this->line("Uploading project archive to cloud storage [{$cloud}]...");
            $localFullPath = base_path($this->archiveFile);
            $stream = fopen($localFullPath, 'r');
            $cloudStorage->put($cloudFolderPrefix . basename($this->archiveFile), $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->comment('Project archive successfully uploaded.');
            $this->newLine();

            if (!$this->option('no-delete')) {
                $this->line('Deleting local project archive...');

                File::delete($localFullPath);

                if ($isBackupDirCreated) {
                    File::deleteDirectory($backupDirFull);
                }

                $this->comment('Local archive successfully deleted.');
            } else {
                // Archive is already in $backupDirName
                $this->comment("Local archive preserved in: {$backupDirFull}");
            }
        } else {
            // No cloud; if user wants to keep the archive and specified a folder, it's already in that folder.
            if ($this->option('no-delete')) {
                $this->comment("Local archive preserved in: {$backupDirFull}");
            }
        }

        $this->newLine();
        $this->info('✔ Project backup was successfully created.');
        return self::SUCCESS;
    }

    protected function prepareExcludeList(?string $excludeList, string $backupDirName): string
    {
        $defaults = [$backupDirName, 'storage/framework/cache', 'vendor'];

        if ($excludeList !== null && $excludeList !== '') {
            $additional = array_map('trim', explode(',', $excludeList));
            $defaults = array_merge($defaults, $additional);
        }

        $defaults = array_unique($defaults);

        return collect($defaults)
            ->filter(static fn($item) => $item !== '')
            ->map(static fn($item) => '--exclude=' . escapeshellarg($item))
            ->implode(' ');
    }
}
