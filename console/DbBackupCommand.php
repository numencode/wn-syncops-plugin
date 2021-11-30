<?php namespace NumenCode\Backup\Console;

use File;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DbBackupCommand extends Command
{
    protected $signature = 'db:backup
        {cloudName?      : Cloud storage where the dump file is uploaded}
        {--folder=       : The name of the folder where the dump file is stored (local and/or on the cloud storage)}
        {--timestamp=    : Date format used for naming the dump file, default: Y-m-d_H-i-s}
        {--d|--no-delete : Do not delete the dump file after the upload to the cloud storage}';

    protected $description = 'Create a database dump and optionally upload it to the cloud storage.';

    protected $dumpFile;

    public function handle()
    {
        $folder = $this->prepareFolder($this->option('folder'), $this->argument('cloudName'));
        $timestamp = $this->option('timestamp') ?: 'Y-m-d_H-i-s';

        $this->dumpFile = Carbon::now()->format($timestamp) . '.sql.gz';

        $connection = config('database.default');
        $dbUser = config('database.connections.' . $connection . '.username');
        $dbPass = config('database.connections.' . $connection . '.password');
        $dbName = config('database.connections.' . $connection . '.database');

        $this->line('');
        $this->question('Creating database dump file...');
        $this->info(shell_exec("mysqldump -u{$dbUser} -p{$dbPass} {$dbName} | gzip > {$this->dumpFileName}"));
        $this->info('Database dump file successfully created.');
        $this->line('');

        if ($this->argument('cloudName')) {
            $cloudStorage = Storage::disk($this->argument('cloudName'));

            $this->question('Uploading database dump file to the cloud storage...');
            $cloudStorage->put($folder . $this->dumpFile, file_get_contents($this->dumpFileName));
            $this->info('Database dump file successfully uploaded.');
            $this->line('');

            if (!$this->option('no-delete')) {
                $this->line('Deleting the database dump file...');
                $this->info(shell_exec("rm -f {$this->dumpFileName}"));
                $this->info('Database dump file successfully deleted.');
                $this->line('');
            } elseif ($folder) {
                $this->moveFile($folder);
            }
        } elseif ($folder) {
            $this->moveFile($folder);
        }

        $this->alert('Database backup was successfully created.');
    }

    protected function prepareFolder($folderName = null, $cloudStorage = null)
    {
        $folderName = $folderName ? rtrim($folderName, '/') . '/' : null;

        if (!$cloudStorage && $folderName && !File::isDirectory($folderName)) {
            File::makeDirectory($folderName, 0777, true, true);
        }

        return $folderName;
    }

    protected function moveFile($folder)
    {
        File::move($this->dumpFile, $folder . $this->dumpFile);
    }
}
