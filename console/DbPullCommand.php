<?php namespace NumenCode\Backup\Console;

class DbPullCommand extends RemoteCommand
{
    protected $signature = 'db:pull
        {server          : The name of the remote server}
        {--i|--no-import : Do not import data automatically}';

    protected $description = 'Create a database dump on a remote server and import it on a local environment.';

    public function handle()
    {
        if (!$this->sshConnect()) {
            return;
        }

        $remoteUser = $this->server['username'];
        $remoteHost = $this->server['host'];
        $remotePath = $this->backup['path'];

        $connection = config('database.default');
        $dbUser = config('database.connections.' . $connection . '.username');
        $dbPass = config('database.connections.' . $connection . '.password');
        $dbName = config('database.connections.' . $connection . '.database');

        $remoteDbName = $this->backup['database']['name'];
        $remoteDbUser = $this->backup['database']['username'];
        $remoteDbPass = $this->backup['database']['password'];
        $remoteDbTables = implode(' ', $this->backup['database']['tables']);

        $this->line('');

        $this->question('Creating database dump file...');
        $this->sshRun(["mysqldump -u{$remoteDbUser} -p{$remoteDbPass} --no-create-info --replace {$remoteDbName} {$remoteDbTables} > database.sql"]);
        $this->line('');
        $this->info('Database dump file created.');
        $this->line('');

        $this->question('Fetching database dump file from the remote server...');
        $this->info(shell_exec("scp {$remoteUser}@{$remoteHost}:{$remotePath}/database.sql database.sql"));
        $this->info('Database dump file successfully received.');
        $this->line('');

        if (!$this->option('no-import')) {
            $this->question('Importing data...');
            $this->info(shell_exec("mysql -u{$dbUser} -p{$dbPass} {$dbName} < database.sql"));
            $this->info('Data imported successfully.');
            $this->line('');
        }

        $this->question('Cleaning the database dump files...');
        $this->sshRun(['rm -f database.sql']);

        if (!$this->option('no-import')) {
            $this->info(shell_exec('rm -f database.sql'));
        }

        $this->info('Cleanup completed successfully.');
        $this->line('');

        $this->alert('Database was successfully updated.');
    }
}
