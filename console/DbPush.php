<?php namespace NumenCode\SyncOps\Console;

use Carbon\Carbon;
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
        {--timestamp=    : Date format used for naming the dump file (default: Y-m-d_H-i-s)}
        {--d|no-delete   : Do not delete the dump file after uploading to the cloud}';

    protected $description = 'Create a compressed database dump and optionally upload it to cloud storage.';

    protected string $dumpFilename;

    public function handle(): int
    {
        $folder = $this->resolveFolderName($this->option('folder'));
        $timestamp = $this->option('timestamp') ?: 'Y-m-d_H-i-s';

        $this->dumpFilename = Carbon::now()->format($timestamp) . '.sql.gz';

        $connection = config('database.default');
        $dbUser = config('database.connections.' . $connection . '.username');
        $dbPass = config('database.connections.' . $connection . '.password');
        $dbName = config('database.connections.' . $connection . '.database');

        $this->line(PHP_EOL . 'Creating database dump file...');
        $this->runLocalCommand("mysqldump -u{$dbUser} -p{$dbPass} {$dbName} | gzip > {$this->dumpFilename}");
        $this->info('Database dump file successfully created.' . PHP_EOL);

        if ($this->argument('cloud')) {
            $cloudStorage = Storage::disk($this->argument('cloud'));

            $this->line('Uploading database dump file to cloud storage...');
            $cloudStorage->put($folder . $this->dumpFilename, file_get_contents($this->dumpFilename));
            $this->info('Database dump file successfully uploaded.' . PHP_EOL);

            if (!$this->option('no-delete')) {
                $this->line('Deleting the local dump file...');
                $this->runLocalCommand("rm -f {$this->dumpFilename}");
                $this->info('Local dump file successfully deleted.' . PHP_EOL);
            } elseif ($folder) {
                $this->moveFile($folder);
            }
        }

        $this->info('✔ Database backup was successfully created.');

        return Command::SUCCESS;
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
