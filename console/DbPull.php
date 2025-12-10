<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use NumenCode\SyncOps\Classes\RemoteExecutor;
use NumenCode\SyncOps\Traits\RunsLocalCommands;
use NumenCode\SyncOps\Support\MysqlCommandBuilder;
use Symfony\Component\Process\Exception\ProcessFailedException;

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
        $timestampFormat = $this->option('timestamp') ?: config('syncops.timestamp', 'Y-m-d_H_i_s');
        $useGzip = !$this->option('no-gzip');

        $timestamp = now()->format($timestampFormat);
        $fileName = "{$timestamp}.sql";
        $localTempFile = base_path($fileName);

        /** @var RemoteExecutor|null $executor */
        $executor = null;
        $remoteTempFile = null;

        try {
            $this->line("Connecting to remote server '{$serverName}'...");
            $executor = $this->createExecutor($serverName);

            if (empty($executor->config['project']['path']) || empty($executor->config['database'])) {
                throw new \RuntimeException("Missing 'project.path' or 'database' configuration for server '{$serverName}'.");
            }

            $remoteConfig = $executor->config['database'];
            $tables = $remoteConfig['tables'] ?? [];
            $remoteBasePath = rtrim($executor->config['project']['path'], '/');
            $remoteTempFile = $remoteBasePath . '/' . $fileName . ($useGzip ? '.gz' : '');

            $this->line("Creating remote database dump...");
            $dumpCommand = MysqlCommandBuilder::dump($remoteConfig, $remoteTempFile, $useGzip, $tables);
            $executor->ssh->runRawCommand($dumpCommand);

            $this->line("Downloading database dump via SFTP...");
            $downloadTarget = $localTempFile . ($useGzip ? '.gz' : '');
            $executor->sftp->download($remoteTempFile, $downloadTarget);

            if ($useGzip) {
                $localTempFile = $downloadTarget;

                if (!is_file($localTempFile)) {
                    throw new \RuntimeException("Expected local gzip file not found: {$localTempFile}");
                }

                $this->line("Unzipping database dump locally...");
                $uncompressedFile = substr($localTempFile, 0, -3); // Remove ".gz"

                $gz = @gzopen($localTempFile, 'rb');
                if ($gz === false) {
                    throw new \RuntimeException("Unable to open gzip file for reading: {$localTempFile}");
                }

                $out = @fopen($uncompressedFile, 'wb');
                if ($out === false) {
                    gzclose($gz);
                    throw new \RuntimeException("Unable to open file for writing: {$uncompressedFile}");
                }

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

                if (!is_array($localDbConfig) || empty($localDbConfig['database'])) {
                    throw new \RuntimeException('Local database configuration is missing or invalid.');
                }

                // Remove first line if MariaDB sandbox directive exists
                $contents = @file($localTempFile);
                if ($contents !== false && isset($contents[0]) && str_starts_with($contents[0], '/*M!999999')) {
                    array_shift($contents);
                    file_put_contents($localTempFile, implode('', $contents));
                }

                $importCommand = MysqlCommandBuilder::import($localDbConfig, $localTempFile);
                $this->runLocalCommand($importCommand);

                $this->comment("Database imported successfully into {$localDbConfig['database']}");
                $this->newLine();
            }
        } catch (ProcessFailedException $e) {
            // Local mysql import failed
            $this->newLine();
            $this->error("✘ Local database import failed:");
            $this->error($e->getProcess()->getErrorOutput() ?: $e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            // Any other failure (remote dump, SFTP, decompression, config, etc.)
            $this->newLine();
            $this->error("✘ An error occurred while pulling the database from server '{$serverName}':");
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $this->line("Cleaning up temporary files...");

            // Delete remote dump
            if ($executor instanceof RemoteExecutor && !empty($remoteTempFile)) {
                try {
                    $executor->ssh->runRawCommand('rm -f ' . escapeshellarg($remoteTempFile));
                } catch (\Throwable $e) {
                    $this->error("✘ Failed to remove remote temp file: " . $e->getMessage());
                }
            }

            // Delete local dump only if it has been imported
            if (!$this->option('no-import') && isset($localTempFile) && File::exists($localTempFile)) {
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

    protected function createExecutor(string $server): RemoteExecutor
    {
        return new RemoteExecutor($server);
    }
}
