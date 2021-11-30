<?php namespace NumenCode\Backup\Console;

use File;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProjectBackupCommand extends Command
{
    protected $signature = 'project:backup
        {cloudName?      : Cloud storage where the archive is uploaded}
        {--folder=       : The name of the folder where the archive is stored (local and/or on the cloud storage)}
        {--timestamp=    : Date format used for naming the archive file, default: Y-m-d_H-i-s}
        {--exclude=      : Exclude folders (comma separated) from the archive, /vendor is excluded by default}
        {--d|--no-delete : Do not delete the archive after the upload to the cloud storage}';

    protected $description = 'Create a tarball archive of all of the project files and optionally upload it to the cloud storage.';

    protected $archiveFile;

    public function handle()
    {
        $folder = $this->prepareFolder($this->option('folder'), $this->argument('cloudName'));
        $timestamp = $this->option('timestamp') ?: 'Y-m-d_H-i-s';
        $exclude = $this->prepareExcludeList($this->option('exclude'));

        $this->archiveFile = Carbon::now()->format($timestamp) . '.tar.gz';

        $this->line('');
        $this->line('Creating project tarball archive...');
        $this->info(shell_exec("tar -pczf {$this->archiveFile} {$exclude} ."));
        $this->info('Project tarball archive successfully created.');
        $this->line('');

        if ($this->argument('cloudName')) {
            $cloudStorage = Storage::disk($this->argument('cloudName'));

            $this->line('Uploading project tarball archive to the cloud storage...');
            $cloudStorage->put($folder . $this->archiveFile, file_get_contents($this->archiveFile));
            $this->info('Project tarball archive successfully uploaded.');
            $this->line('');

            if (!$this->option('no-delete')) {
                $this->line('Deleting the project tarball archive...');
                $this->info(shell_exec("rm -f {$this->archiveFile}"));
                $this->info('Project tarball archive successfully deleted.');
                $this->line('');
            } elseif ($folder) {
                $this->moveFile($folder);
            }
        } elseif ($folder) {
            $this->moveFile($folder);
        }

        $this->alert('Project backup successfully created.');
    }

    protected function prepareFolder($folderName = null, $cloudStorage = null)
    {
        $folderName = $folderName ? rtrim($folderName, '/') . '/' : null;

        if (!$cloudStorage && $folderName && !File::isDirectory($folderName)) {
            File::makeDirectory($folderName, 0777, true, true);
        }

        return $folderName;
    }

    protected function prepareExcludeList($excludeList = null)
    {
        $exclude = '--exclude=vendor';

        if ($excludeList) {
            collect(explode(',', $excludeList))->each(function ($item) use (&$exclude) {
                $exclude .= ' --exclude=' . $item;
            });
        }

        return $exclude;
    }

    protected function moveFile($folder)
    {
        File::move($this->archiveFile, $folder . $this->archiveFile);
    }
}
