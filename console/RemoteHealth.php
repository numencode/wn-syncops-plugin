<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class RemoteHealth extends Command
{
    protected $signature = 'syncops:remote-health
        {server : The name of the remote server}
        {--full : Run extended checks (PHP extensions, database connectivity, artisan)}';

    protected $description = 'Run a set of health checks on a remote server (system, PHP, database, Git, project path).';

    public function handle(): int
    {
        $this->newLine();

        $serverName = $this->argument('server');
        $isFull = (bool) $this->option('full');

        try {
            $this->line("Checking remote health for server '{$serverName}'...");
            $this->newLine();

            $executor = $this->createExecutor($serverName);

            $this->checkSystem($executor);
            $this->newLine();

            $this->checkPhp($executor, $isFull);
            $this->newLine();

            $this->checkDatabase($executor, $isFull);
            $this->newLine();

            $this->checkProject($executor);
            $this->newLine();

            $this->info("✔ Remote health check completed for '{$serverName}'. See details above.");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("✘ Failed to run remote health check for '{$serverName}':");
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Basic OS / system-level checks (uptime, disk usage).
     */
    protected function checkSystem(RemoteExecutor $executor): void
    {
        $this->line('System checks:');

        try {
            $uptime = $executor->ssh->runAndGet(['uptime']);
            $this->comment('  Uptime: ' . $uptime);
        } catch (\Throwable $e) {
            $this->warn("  Unable to retrieve uptime: {$e->getMessage()}");
        }

        try {
            $df = $executor->ssh->runAndGet(['df', '-h']);
            $this->comment('  Disk usage (df -h):');
            $this->line($df);
        } catch (\Throwable $e) {
            $this->warn("  Unable to retrieve disk usage: {$e->getMessage()}");
        }
    }

    /**
     * PHP runtime checks (version, and optionally extensions in full mode).
     */
    protected function checkPhp(RemoteExecutor $executor, bool $isFull): void
    {
        $this->line('PHP checks:');

        try {
            $phpVersion = $executor->ssh->runAndGet(['php', '-v']);
            $this->comment('  Version: ' . $this->firstLine($phpVersion));
        } catch (\Throwable $e) {
            $this->warn("  Unable to retrieve PHP version: {$e->getMessage()}");
            return;
        }

        if ($isFull) {
            try {
                $modules = $executor->ssh->runAndGet(['php', '-m']);
                $this->comment('  Loaded modules (php -m):');
                $this->line($modules);
            } catch (\Throwable $e) {
                $this->warn("  Unable to retrieve PHP modules: {$e->getMessage()}");
            }
        }
    }

    /**
     * Optional database checks (version + connection test, only if database config is present).
     */
    protected function checkDatabase(RemoteExecutor $executor, bool $isFull): void
    {
        if (empty($executor->config['database']['database'])) {
            $this->line('Database checks:');
            $this->comment('  No database configuration found in syncops connection. Skipping.');
            return;
        }

        $this->line('Database checks:');

        $client = 'mysql';

        try {
            [$client, $versionOutput] = $this->detectDatabaseClient($executor);
            $label = $client === 'mariadb' ? 'MariaDB' : 'MySQL';
            $this->comment("  {$label} client: " . $this->firstLine($versionOutput));
        } catch (\Throwable $e) {
            $this->warn("  Unable to retrieve MySQL/MariaDB version: {$e->getMessage()}");
            return;
        }

        if (!$isFull) {
            return;
        }

        $dbConfig = $executor->config['database'];

        if (empty($dbConfig['username'])) {
            $this->comment('  Database connectivity check skipped (no username configured).');
            return;
        }

        try {
            // Build command as:
            // MYSQL_PWD='password' mysql|mariadb -u'user' -e "SELECT 1" 'database'
            $parts = [];

            if (array_key_exists('password', $dbConfig) && $dbConfig['password'] !== '') {
                // Safely escape for single-quoted shell string: 'foo' -> 'foo', but "a'b" -> 'a'"'"'b'
                $escapedPwd = str_replace("'", "'\"'\"'", $dbConfig['password']);
                $parts[] = "MYSQL_PWD='{$escapedPwd}'";
            }

            $parts[] = $client;

            // Username
            $parts[] = '-u' . escapeshellarg($dbConfig['username']);

            // Probe query
            $parts[] = '-e "SELECT 1"';

            // Optional database name
            if (!empty($dbConfig['database'])) {
                $parts[] = escapeshellarg($dbConfig['database']);
            }

            $cmd = implode(' ', $parts);

            $executor->ssh->runRawCommand($cmd);
            $this->comment('  Database connectivity: OK (SELECT 1 succeeded).');
        } catch (\Throwable $e) {
            $this->warn("  Database connectivity check failed: {$e->getMessage()}");
        }
    }

    /**
     * Project-level checks (path, Git state, Laravel/Winter version).
     */
    protected function checkProject(RemoteExecutor $executor): void
    {
        $this->line('Project checks:');

        // Project path / working directory
        try {
            $pwd = $executor->ssh->runAndGet(['pwd']);
            $this->comment("  Working directory (pwd): {$pwd}");
        } catch (\Throwable $e) {
            $this->warn("  Unable to confirm project path (pwd): {$e->getMessage()}");
        }

        // Git status
        try {
            $isClean = $executor->ssh->remoteIsClean();
            $branch = $executor->ssh->runAndGet(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);

            if ($isClean) {
                $this->info("  Git: working tree is clean on branch '{$branch}'.");
            } else {
                $this->warn("  Git: working tree is NOT clean on branch '{$branch}'.");
            }
        } catch (\Throwable $e) {
            $this->warn("  Unable to retrieve Git status: {$e->getMessage()}");
        }

        // Laravel version check – non-fatal if it fails.
        try {
            $laravelVersion = $executor->ssh->runAndGet(['php', 'artisan', '--version']);
            $this->comment("  Framework: {$laravelVersion}");
        } catch (\Throwable $e) {
            $this->warn("  Unable to run 'php artisan --version': {$e->getMessage()}");
        }

        // Winter CMS version check – non-fatal if it fails.
        try {
            $winterRaw = $executor->ssh->runAndGet(['php', 'artisan', 'winter:version']);
            $lines = preg_split('/\r\n|\r|\n/', trim($winterRaw));

            $detectedLine = null;

            foreach ($lines as $line) {
                if (stripos($line, 'Detected Winter CMS build') !== false) {
                    $detectedLine = $line;
                    break;
                }
            }

            // Fallback: if we didn't match, just use the last line.
            if ($detectedLine === null && !empty($lines)) {
                $detectedLine = end($lines);
            }

            // Strip leading asterisks and whitespace like "*** "
            $detectedLine = ltrim($detectedLine ?? '', "* \t");

            $this->comment("  Winter CMS: {$detectedLine}");
        } catch (\Throwable $e) {
            $this->warn("  Unable to run 'php artisan winter:version': {$e->getMessage()}");
        }
    }

    /**
     * Try to determine which client is available and return [clientName, versionOutput].
     * Prefer "mariadb" when available, fall back to "mysql".
     *
     * @throws \Throwable when neither client is available or both fail.
     */
    protected function detectDatabaseClient(RemoteExecutor $executor): array
    {
        // Prefer mariadb when available, since "mysql" may be a deprecated alias.
        try {
            $output = $executor->ssh->runAndGet(['mariadb', '--version']);
            if (trim($output) !== '') {
                return ['mariadb', $output];
            }
        } catch (\Throwable $e) {
            // Ignore and fall back to mysql
        }

        // Fallback to mysql – this covers true MySQL servers and legacy MariaDB aliases.
        $output = $executor->ssh->runAndGet(['mysql', '--version']);

        return ['mysql', $output];
    }

    /**
     * Return the first line of a multi-line string.
     */
    protected function firstLine(string $text): string
    {
        $line = strtok($text, "\n");
        return $line === false ? '' : $line;
    }

    /**
     * Factory method for obtaining a RemoteExecutor instance.
     * Kept protected so tests can override it.
     */
    protected function createExecutor(string $server): RemoteExecutor
    {
        return new RemoteExecutor($server);
    }
}
