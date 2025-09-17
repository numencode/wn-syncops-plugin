<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use NumenCode\SyncOps\Traits\RunsLocalCommands;

class DbPush extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:db-push
        {cloud?          : Cloud storage where the dump file is uploaded}
        {--folder=       : Folder where the dump file is stored (local and/or cloud)}
        {--timestamp=    : Date format used for naming the dump file}
        {--g|no-gzip     : Skip gzip compression when creating the database dump}
        {--d|no-delete   : Do not delete the dump file after uploading to the cloud}';

    protected $description = 'Create a database dump (compressed by default) and optionally upload it to cloud storage.';

    protected string $dumpFilename;

    public function handle(): int
    {
        $folder = $this->resolveFolderName($this->option('folder'));
        $timestamp = $this->option('timestamp') ?: config('syncops.timestamp', 'Y-m-d_H_i_s');
        $useGzip = !$this->option('no-gzip');

        $this->dumpFilename = now()->format($timestamp) . ($useGzip ? '.sql.gz' : '.sql');

        $connection = config('database.default');
        $dbUser = config('database.connections.' . $connection . '.username');
        $dbPass = config('database.connections.' . $connection . '.password');
        $dbName = config('database.connections.' . $connection . '.database');

        $this->line(PHP_EOL . "Creating database dump file...");
        $dumpCommand = $useGzip
            ? "mysqldump -u{$dbUser} -p{$dbPass} {$dbName} | gzip > {$this->dumpFilename}"
            : "mysqldump -u{$dbUser} -p{$dbPass} {$dbName} > {$this->dumpFilename}";
        $this->runLocalCommand($dumpCommand);
        $this->info("Database dump file successfully created: {$this->dumpFilename}" . PHP_EOL);

        if ($this->argument('cloud')) {
            $cloudStorage = Storage::disk($this->argument('cloud'));

            $this->line("Uploading database dump file to cloud storage...");
            $localFileStream = fopen($this->dumpFilename, 'r');
            $cloudStorage->put($folder . $this->dumpFilename, $localFileStream);
            if (is_resource($localFileStream)) {
                fclose($localFileStream);
            }
            $this->info("Database dump file successfully uploaded." . PHP_EOL);

            if (!$this->option('no-delete')) {
                $this->line("Deleting the local dump file...");
                File::delete($this->dumpFilename);
                $this->info("Local dump file successfully deleted." . PHP_EOL);
            } elseif ($folder) {
                $this->moveFile($folder);
            }
        }

        $this->info("✔ Database backup was successfully created.");
        return self::SUCCESS;
    }

    protected function resolveFolderName(?string $folderName): ?string
    {
        return $folderName ? rtrim($folderName, '/') . '/' : null;
    }

    protected function moveFile(string $folder): void
    {
        if (!File::isDirectory($folder)) {
            File::makeDirectory($folder, 0777, true, true);
        }

        File::move($this->dumpFilename, $folder . $this->dumpFilename);
    }
}
