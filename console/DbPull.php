<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use NumenCode\SyncOps\Classes\RemoteExecutor;
use NumenCode\SyncOps\Traits\RunsLocalCommands;
use NumenCode\SyncOps\Support\MysqlCommandBuilder;

class DbPull extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:db-pull
        {server        : The name of the remote server}
        {--timestamp=  : Date format used for naming the dump file}
        {--g|no-gzip   : Skip gzip compression when creating the database dump}
        {--i|no-import : Do not import the database dump locally}';

    protected $description = 'Create a database dump on a remote server, download it, and import it locally.';

    public function handle(): int
    {
        $this->newLine();

        $serverName = $this->argument('server');
        $timestamp = $this->option('timestamp') ?: config('syncops.timestamp', 'Y-m-d_H_i_s');
        $useGzip = !$this->option('no-gzip');
        $timestamp = now()->format($timestamp);
        $fileName = "{$timestamp}.sql";
        $localTempFile = base_path($fileName);

        try {
            $this->line("Connecting to remote server '{$serverName}'...");
            $executor = new RemoteExecutor($serverName);
            $remoteConfig = $executor->config['database'];
            $remoteTempFile = rtrim($executor->config['path'], '/') . '/' . $fileName . ($useGzip ? '.gz' : '');

            $this->line("Creating remote database dump...");
            $dumpCommand = MysqlCommandBuilder::dump($remoteConfig, $remoteTempFile, $useGzip, $remoteConfig['tables']);
            $executor->ssh->runRawCommand($dumpCommand);

            $this->line("Downloading database dump via SFTP...");
            $executor->sftp->download($remoteTempFile, $localTempFile . ($useGzip ? '.gz' : ''));

            if ($useGzip) {
                $localTempFile .= '.gz';
                $this->line("Unzipping database dump locally...");
                $uncompressedFile = substr($localTempFile, 0, -3); // Remove .gz
                $gz = gzopen($localTempFile, 'rb');
                $out = fopen($uncompressedFile, 'wb');
                while (!gzeof($gz)) {
                    fwrite($out, gzread($gz, 4096));
                }
                gzclose($gz);
                fclose($out);
                File::delete($localTempFile); // Remove .gz after decompression
                $localTempFile = $uncompressedFile;
            }

            $this->comment("Database dump downloaded to {$localTempFile}");
            $this->newLine();

            if (!$this->option('no-import')) {
                $this->line("Importing local database...");
                $localDbConfig = config('database.connections.' . config('database.default'));

                // Remove first line if MariaDB sandbox directive exists
                $contents = file($localTempFile);
                if (str_starts_with($contents[0], '/*M!999999')) {
                    array_shift($contents);
                    file_put_contents($localTempFile, implode('', $contents));
                }

                $importCommand = MysqlCommandBuilder::import($localDbConfig, $localTempFile);
                $this->runLocalCommand($importCommand);
                $this->comment("Database imported successfully into {$localDbConfig['database']}");
                $this->newLine();
            }
        } catch (\Exception $e) {
            $this->newLine();
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            $this->line("Cleaning up temporary files...");

            // Delete remote dump
            if (isset($executor->ssh) && isset($remoteTempFile)) {
                try {
                    $executor->ssh->runRawCommand('rm -f ' . escapeshellarg($remoteTempFile));
                } catch (\Exception $e) {
                    $this->error("✘ Failed to remove remote temp file: " . $e->getMessage());
                }
            }

            // Delete local dump
            if (!$this->option('no-import') && File::exists($localTempFile)) {
                File::delete($localTempFile);
            }

            $this->comment("Cleanup complete.");
        }

        $this->newLine();

        if ($this->option('no-import')) {
            $this->info("✔ Database dump saved to: {$localTempFile}");
        } else {
            $this->info("✔ Database successfully pulled and imported.");
        }

        return self::SUCCESS;
    }
}
