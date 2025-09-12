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
        {--timestamp=  : Date format used for naming the dump file (default: Y-m-d_H-i-s)}
        {--g|no-gzip   : Skip gzip compression when creating the database dump}
        {--i|no-import : Do not import the database dump locally}';

    protected $description = 'Creates a database dump on a remote server, downloads it, and imports it locally.';

    public function handle(): int
    {
        $serverName = $this->argument('server');
        $timestamp = $this->option('timestamp') ?: 'Y-m-d_H-i-s';
        $useGzip = !$this->option('no-gzip');
        $timestamp = now()->format($timestamp);
        $fileName = "db_{$timestamp}.sql";
        $localTempFile = base_path($fileName);
        $executor = null;
        $remoteTempFile = null;

        try {
            $this->comment("Connecting to remote server '{$serverName}'...");
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

            $this->info("Database dump downloaded to {$localTempFile}");

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
                $this->info("Database imported successfully into {$localDbConfig['database']}");
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            $this->comment("Cleaning up temporary files...");

            // Delete remote dump
            if (isset($executor->ssh) && $remoteTempFile) {
                try {
                    $executor->ssh->runRawCommand('rm -f ' . escapeshellarg($remoteTempFile));
                } catch (\Exception $e) {
                    $this->error("Failed to remove remote temp file: " . $e->getMessage());
                }
            }

            // Delete local dump
            if (!$this->option('no-import') && File::exists($localTempFile)) {
                File::delete($localTempFile);
            }

            $this->info("Cleanup complete.");
        }

        if ($this->option('no-import')) {
            $this->info(PHP_EOL . "✔ Database dump saved to: {$localTempFile}");
        } else {
            $this->info(PHP_EOL . "✔ Database successfully pulled and imported.");
        }

        return self::SUCCESS;
    }
}
