<?php namespace NumenCode\SyncOps\Console;

use File;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DbPush extends Command
{
    protected $signature = 'syncops:db-push
        {cloud?          : Cloud storage where the dump file is uploaded}
        {--folder=       : The name of the folder where the dump file is stored (local and/or on the cloud storage)}
        {--timestamp=    : Date format used for naming the dump file, default: Y-m-d_H-i-s}
        {--d|--no-delete : Do not delete the dump file after the upload to the cloud storage}';

    protected $description = 'Create a database dump and optionally upload it to the cloud storage.';

    protected string $dumpFilename;

    public function handle()
    {
        $folder = $this->resolveFolderName($this->option('folder'));
        $timestamp = $this->option('timestamp') ?: 'Y-m-d_H-i-s';

        $this->dumpFilename = Carbon::now()->format($timestamp) . '.sql.gz';

        $connection = config('database.default');
        $dbUser = config('database.connections.' . $connection . '.username');
        $dbPass = config('database.connections.' . $connection . '.password');
        $dbName = config('database.connections.' . $connection . '.database');

        $this->line(PHP_EOL . 'Creating database dump file...');
        shell_exec("mysqldump -u{$dbUser} -p{$dbPass} {$dbName} | gzip > {$this->dumpFilename}");
        $this->info('Database dump file successfully created.' . PHP_EOL);

        if ($this->argument('cloud')) {
            $cloudStorage = Storage::disk($this->argument('cloud'));

            $this->line('Uploading database dump file to the cloud storage...');
            $cloudStorage->put($folder . $this->dumpFilename, file_get_contents($this->dumpFilename));
            $this->info('Database dump file successfully uploaded.' . PHP_EOL);

            if (!$this->option('no-delete')) {
                $this->line('Deleting the database dump file...');
                shell_exec("rm -f {$this->dumpFilename}");
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

        File::move($this->dumpFilename, $folder . $this->dumpFilename);
    }
}
