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
        $folder = $this->resolveFolderName($this->option('folder'));
        $timestamp = $this->option('timestamp') ?: 'Y-m-d_H-i-s';

        $this->dumpFile = Carbon::now()->format($timestamp) . '.sql.gz';

        $connection = config('database.default');
        $dbUser = config('database.connections.' . $connection . '.username');
        $dbPass = config('database.connections.' . $connection . '.password');
        $dbName = config('database.connections.' . $connection . '.database');

        $this->line(PHP_EOL . 'Creating database dump file...');
        shell_exec("mysqldump -u{$dbUser} -p{$dbPass} {$dbName} | gzip > {$this->dumpFile}");
        $this->info('Database dump file successfully created.' . PHP_EOL);

        if ($this->argument('cloudName')) {
            $cloudStorage = Storage::disk($this->argument('cloudName'));

            $this->line('Uploading database dump file to the cloud storage...');
            $cloudStorage->put($folder . $this->dumpFile, file_get_contents($this->dumpFile));
            $this->info('Database dump file successfully uploaded.' . PHP_EOL);

            if (!$this->option('no-delete')) {
                $this->line('Deleting the database dump file...');
                shell_exec("rm -f {$this->dumpFile}");
                $this->info('Database dump file successfully deleted.' . PHP_EOL);
            } elseif ($folder) {
                $this->moveFile($folder);
            }
        }

        $this->alert('Database backup was successfully created.');
    }

    protected function resolveFolderName($folderName = null)
    {
        return $folderName ? rtrim($folderName, '/') . '/' : null;
    }

    protected function moveFile($folder)
    {
        if (!File::isDirectory($folder)) {
            File::makeDirectory($folder, 0777, true, true);
        }

        File::move($this->dumpFile, $folder . $this->dumpFile);
    }
}
