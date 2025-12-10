<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use NumenCode\SyncOps\Traits\RunsLocalCommands;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DbPush extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:db-push
        {cloud?        : Cloud storage where the dump file is uploaded}
        {--folder=     : Folder where the dump file is stored (local and/or cloud)}
        {--timestamp=  : Date format used for naming the dump file}
        {--g|no-gzip   : Skip gzip compression when creating the database dump}
        {--d|no-delete : Do not delete the dump file after uploading to the cloud}';

    protected $description = 'Create a database dump (compressed by default) and optionally upload it to cloud storage.';

    protected string $dumpFilename;

    public function handle(): int
    {
        $this->newLine();

        $cloud = $this->argument('cloud');
        $folder = format_path($this->option('folder'));
        $timestamp = $this->option('timestamp') ?: config('syncops.timestamp', 'Y-m-d_H_i_s');
        $useGzip = ! (bool) $this->option('no-gzip');

        $this->dumpFilename = now()->format($timestamp) . ($useGzip ? '.sql.gz' : '.sql');

        // Read DB config once and validate
        $connection = config('database.default');
        $dbConfig = config('database.connections.' . $connection, []);

        $dbUser = $dbConfig['username'] ?? null;
        $dbPass = $dbConfig['password'] ?? '';
        $dbName = $dbConfig['database'] ?? null;

        if (!$dbUser || !$dbName) {
            $this->error('✘ Database configuration is missing "username" and/or "database" for the default connection.');
            return self::FAILURE;
        }

        try {
            $this->line('Creating database dump file...');

            // Keep original command structure to avoid breaking behaviour/tests.
            $dumpCommand = $useGzip
                ? "mysqldump -u{$dbUser} -p{$dbPass} {$dbName} | gzip > {$this->dumpFilename}"
                : "mysqldump -u{$dbUser} -p{$dbPass} {$dbName} > {$this->dumpFilename}";

            $this->runLocalCommand($dumpCommand);

            $this->comment("Database dump file successfully created: {$this->dumpFilename}");
            $this->newLine();

            if ($cloud) {
                // Validate cloud disk configuration
                try {
                    $cloudStorage = Storage::disk($cloud);
                } catch (\InvalidArgumentException $e) {
                    $this->error("✘ The cloud storage disk '{$cloud}' is not configured. Please check your config/filesystems.php.");
                    return self::FAILURE;
                }

                $cloudPath = ($folder ?? '') . $this->dumpFilename;

                $this->line("Uploading database dump file to cloud storage '{$cloud}'...");

                $localFileStream = fopen($this->dumpFilename, 'r');

                try {
                    $cloudStorage->put($cloudPath, $localFileStream);
                } finally {
                    if (is_resource($localFileStream)) {
                        fclose($localFileStream);
                    }
                }

                $this->comment('Database dump file successfully uploaded.');
                $this->newLine();

                if (! $this->option('no-delete')) {
                    $this->line('Deleting the local dump file...');
                    File::delete($this->dumpFilename);
                    $this->comment('Local dump file successfully deleted.');
                    $this->newLine();
                } elseif ($folder) {
                    // Only move if a local folder was specified
                    $this->moveFile($folder);
                }
            }

            $this->info('✔ Database backup was successfully created.');
            return self::SUCCESS;
        } catch (ProcessFailedException $e) {
            $this->newLine();
            $this->error('✘ Failed to create database dump:');
            $output = $e->getProcess()->getErrorOutput() ?: $e->getMessage();
            $this->error($output);

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('✘ An unexpected error occurred while creating the database backup:');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Move the dump file into the given folder, creating it if necessary.
     *
     * @param string $folder A path with a trailing slash, e.g. "local/backups/"
     */
    protected function moveFile(string $folder): void
    {
        if (!File::isDirectory($folder)) {
            File::makeDirectory($folder, 0777, true, true);
        }

        File::move($this->dumpFilename, $folder . $this->dumpFilename);
    }
}
