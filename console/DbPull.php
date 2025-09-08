<?php namespace NumenCode\SyncOps\Console;

//use NumenCode\SyncOps\Classes\RemoteExecutor;
//
//class DbPull extends RemoteCommand
//{
//    protected $signature = 'db:pull
//        {server          : The name of the remote server}
//        {--i|--no-import : Do not import data automatically}';
//
//    protected $description = 'Create a database dump on a remote server and import it on a local environment.';
//
//    public function handle(RemoteExecutor $executor)
//    {
//        $this->comment("Connecting to remote server '{$this->argument('server')}'...");
//        $executor->connect($this->argument('server'));
//
//        $remoteUser = $this->server['username'];
//        $remoteHost = $this->server['host'];
//        $remotePath = $this->backup['path'];
//
//        $connection = config('database.default');
//        $dbUser = config('database.connections.' . $connection . '.username');
//        $dbPass = config('database.connections.' . $connection . '.password');
//        $dbName = config('database.connections.' . $connection . '.database');
//
//        $remoteDbName = $this->backup['database']['name'];
//        $remoteDbUser = $this->backup['database']['username'];
//        $remoteDbPass = $this->backup['database']['password'];
//        $remoteDbTables = implode(' ', $this->backup['database']['tables']);
//
//        $this->line(PHP_EOL . "Creating database dump file on the {$this->argument('server')} server...");
//        $this->sshRun(["mysqldump -u{$remoteDbUser} -p{$remoteDbPass} --no-create-info --replace {$remoteDbName} {$remoteDbTables} > database.sql"]);
//        $this->info('Database dump file created.' . PHP_EOL);
//
//        $this->line("Fetching database dump file from the {$this->argument('server')} server...");
//        shell_exec("scp {$remoteUser}@{$remoteHost}:{$remotePath}/database.sql database.sql");
//        $this->info('Database dump file successfully received.' . PHP_EOL);
//
//        if (!$this->option('no-import')) {
//            $this->line('Importing data...');
//            shell_exec("mysql -u{$dbUser} -p{$dbPass} {$dbName} < database.sql");
//            $this->info('Data imported successfully.' . PHP_EOL);
//        }
//
//        $this->line('Cleaning the database dump files...');
//        $this->sshRun(['rm -f database.sql']);
//
//        if (!$this->option('no-import')) {
//            shell_exec('rm -f database.sql');
//        }
//
//        $this->info('Cleanup completed successfully.' . PHP_EOL);
//
//        if ($this->option('no-import')) {
//            $this->alert("Database was successfully fetched from the {$this->argument('server')} server.");
//        } else {
//            $this->alert('Database was successfully updated.');
//        }
//    }
//}

// MODIFIED:
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use NumenCode\SyncOps\Classes\RemoteExecutor;
use NumenCode\SyncOps\Traits\RunsLocalCommands;

class DbPull extends Command
{
    use RunsLocalCommands;

    // 1. CONSISTENCY: Changed signature to match the 'syncops' namespace
    protected $signature = 'syncops:db-pull
        {server        : The name of the remote server}
        {--i|no-import : Do not import the database dump locally}';

    protected $description = 'Creates a database dump on a remote server, downloads it, and imports it locally.';

    public function handle(RemoteExecutor $executor): int
    {
        // 2. ROBUSTNESS: Use a temporary file to avoid name collisions and stale data
        $localTempFile = tempnam(sys_get_temp_dir(), 'db_pull_');
        $remoteTempFile = '/tmp/' . basename($localTempFile);
        try {
            // --- REMOTE OPERATIONS ---
            $this->comment("Connecting to remote server '{$this->argument('server')}'...");
            $executor->connect($this->argument('server'));
            // 3. CLARITY: Get remote config directly from the executor
            $remoteConfig = $executor->config['database'];
            $remotePath = $executor->config['path'];
            $this->line('Creating remote database dump...');
            // 4. SECURITY: Use MYSQL_PWD to protect the remote database password
            $remoteDbUser = escapeshellarg($remoteConfig['username']);
            $remoteDbName = escapeshellarg($remoteConfig['database']);
            $remoteTempFileArg = escapeshellarg($remoteTempFile);
            $password = $remoteConfig['password'];
            $dumpCommand = "export MYSQL_PWD='{$password}'; mysqldump -u{$remoteDbUser} {$remoteDbName} > {$remoteTempFileArg}";
            $executor->runAndGet([$dumpCommand]); // Using the consistent executor method
            // 5. PERFORMANCE & RELIABILITY: Use SFTP over the existing SSH connection
            $this->line("Downloading database dump via SFTP...");
            $sftp = $executor->getSftp();
            if (!$sftp->get($remoteTempFile, $localTempFile)) {
                throw new \RuntimeException("Failed to download dump file via SFTP.");
            }
            $this->info("Database dump successfully downloaded.");
            // --- LOCAL OPERATIONS ---
            if (!$this->option('no-import')) {
                $this->line('Importing local database...');
                $localConnection = config('database.default');
                $localDbConfig = config('database.connections.' . $localConnection);
                // 4. SECURITY: Use MYSQL_PWD to protect the local database password
                $localDbUser = escapeshellarg($localDbConfig['username']);
                $localDbName = escapeshellarg($localDbConfig['database']);
                $localTempFileArg = escapeshellarg($localTempFile);
                $password = $localDbConfig['password'];
                $importCommand = "export MYSQL_PWD='{$password}'; mysql -u{$localDbUser} {$localDbName} < {$localTempFileArg}";
                $this->runLocalCommand($importCommand); // Using the consistent local command runner
                $this->info('Database imported successfully.');
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            // 6. ROBUSTNESS: The 'finally' block ensures cleanup happens even if an error occurs
            $this->comment('Cleaning up temporary files...');
            if (isset($executor) && $executor->isConnected()) {
                $executor->runAndGet(['rm', '-f', $remoteTempFile]);
            }
            if (File::exists($localTempFile)) {
                File::delete($localTempFile);
            }
            $this->info('Cleanup complete.');
        }
        if ($this->option('no-import')) {
            $this->info(PHP_EOL . "✔ Database dump was successfully saved to: {$localTempFile}");
        } else {
            $this->info(PHP_EOL . '✔ Database was successfully pulled and imported.');
        }

        return self::SUCCESS;
    }
}
