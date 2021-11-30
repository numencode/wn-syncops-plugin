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
        $folder = $this->resolveFolderName($this->option('folder'));
        $timestamp = $this->option('timestamp') ?: 'Y-m-d_H-i-s';
        $exclude = $this->prepareExcludeList($this->option('exclude'));

        $this->archiveFile = Carbon::now()->format($timestamp) . '.tar.gz';

        $this->line(PHP_EOL . 'Creating project archive...');
        shell_exec("tar -pczf {$this->archiveFile} {$exclude} .");
        $this->info('Project archive successfully created.' . PHP_EOL);

        if ($this->argument('cloudName')) {
            $cloudStorage = Storage::disk($this->argument('cloudName'));

            $this->line('Uploading project archive to the cloud storage...');
            $cloudStorage->put($folder . $this->archiveFile, file_get_contents($this->archiveFile));
            $this->info('Project archive successfully uploaded.' . PHP_EOL);

            if (!$this->option('no-delete')) {
                $this->line('Deleting the project archive...');
                shell_exec("rm -f {$this->archiveFile}");
                $this->info('Project archive successfully deleted.' . PHP_EOL);
            } elseif ($folder) {
                $this->moveFile($folder);
            }
        }

        $this->alert('Project backup was successfully created.');
    }

    protected function resolveFolderName($folderName = null)
    {
        return $folderName ? rtrim($folderName, '/') . '/' : null;
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
        if (!File::isDirectory($folder)) {
            File::makeDirectory($folder, 0777, true, true);
        }

        File::move($this->archiveFile, $folder . $this->archiveFile);
    }
}
