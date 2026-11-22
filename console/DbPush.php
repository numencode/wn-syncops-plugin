<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use NumenCode\SyncOps\Classes\ConnectionConfig;
use NumenCode\SyncOps\Classes\DatabaseDump;
use NumenCode\SyncOps\Classes\SyncOpsCommand;

/**
 * The reverse of db-pull: replaces a server's database with the local one (or with a dump file).
 * The server's database is backed up first (downloaded into storage/backups/db-push), so it can be restored
 * with --file.
 */
class DbPush extends SyncOpsCommand
{
    protected $signature = 'syncops:db-push
        {server        : The connection name from config/syncops.php}
        {--tables=     : Only these tables (comma-separated; overrides the connection\'s "tables")}
        {--file=       : Push this dump (.sql or .sql.gz, e.g. from db-backup or db-pull --no-import) instead of the local database}
        {--no-backup   : Don\'t back up the server\'s database first}
        {--timestamp=  : Date format for the backup file name}
        {--force       : Replace the server\'s database without asking}';

    protected $description = 'Dump the local database, upload it and import it into the database of a server (the reverse of db-pull).';

    protected function process(): int
    {
        $name = (string) $this->argument('server');
        $file = $this->option('file') ? (string) $this->option('file') : null;
        $tables = $this->tables();

        if ($file !== null && $tables !== []) {
            $this->error('✘ --tables can\'t be combined with --file (the dump is pushed as it is).');

            return self::FAILURE;
        }

        if ($file !== null && !is_file($file)) {
            $this->error("✘ The file {$file} doesn't exist.");

            return self::FAILURE;
        }

        $local = $file === null ? DatabaseDump::localConnection() : null;

        if ($local !== null) {
            $this->redactor->add([(string) ($local['password'] ?? '')]);
        }

        // Asked before connecting, like db-pull
        $config = ConnectionConfig::fromConfig($name);
        $remoteDatabase = $config->database['database'] ?? null;

        if ($remoteDatabase === null) {
            $this->error("✘ No database is configured for '{$name}' (database.database, database.username).");

            return self::FAILURE;
        }

        // --tables overrides the connection's "tables" (not used with --file)
        if ($file === null && $tables === []) {
            $tables = $config->database['tables'] ?? [];
        }

        $source = ($file !== null ? "the dump {$file}" : "the local database '{$local['database']}'")
            . ($tables !== [] ? ' (tables: ' . implode(', ', $tables) . ')' : '');

        if (!$this->confirmPush($name, "This REPLACES the database '{$remoteDatabase}' on '{$name}' with {$source}.")) {
            return self::FAILURE;
        }

        $server = $this->server($name);

        $remoteTemp = $server->makeTempDirectory();
        $this->onCleanup(static fn () => $server->remove($remoteTemp));
        $localTemp = $this->localTempDirectory();

        if (!$this->option('no-backup')) {
            $backup = $this->backupDirectory('db-push') . DIRECTORY_SEPARATOR . $this->timestamp() . "_{$name}_{$remoteDatabase}.sql.gz";
            $remoteBackup = $this->step(
                "Backing up '{$remoteDatabase}' on '{$name}'",
                static fn () => DatabaseDump::dumpRemote($server, $remoteTemp, $tables)
            );
            $this->step('Downloading the backup (' . $this->bytes($server->size($remoteBackup) ?? 0) . ')', static fn () => $server->download($remoteBackup, $backup));
            @chmod($backup, 0640);
            $server->remove($remoteBackup);
            $this->line("  Restore it with: php artisan syncops:db-push {$name} --file=\"{$backup}\"");
        }

        if ($file === null) {
            $file = $localTemp . '/dump.sql.gz';
            $this->step(
                "Dumping the local database '{$local['database']}'" . ($tables !== [] ? ' (' . count($tables) . ' tables)' : ''),
                static fn () => DatabaseDump::dumpLocal($local, $localTemp . '/client.cnf', $file, true, $tables)
            );
        }

        $remoteDump = $remoteTemp . (str_ends_with(strtolower($file), '.gz') ? '/push.sql.gz' : '/push.sql');
        $this->step('Uploading the dump (' . $this->bytes((int) filesize($file)) . ')', static fn () => $server->upload($file, $remoteDump));
        $this->step(
            "Importing into '{$remoteDatabase}' on '{$name}'",
            static fn () => DatabaseDump::importRemote($server, $remoteTemp, $remoteDump)
        );

        // The server's caches may hold data of the old database (settings, translations, …)
        $cache = $server->run([...$server->php(), 'artisan', 'cache:clear', '--no-interaction']);

        if (!$cache->successful()) {
            $this->warn("⚠ The database was imported, but cache:clear failed on '{$name}': " . trim($cache->output));
        }

        $this->newLine();
        $this->info("✔ The database '{$remoteDatabase}' on '{$name}' now has the data of {$source}.");

        return self::SUCCESS;
    }

    /**
     * Overwriting a server's database is asked with the connection name to type (--force skips it).
     */
    protected function confirmPush(string $name, string $message): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (!$this->input->isInteractive()) {
            $this->error('✘ Confirmation needed: run the command interactively or add --force.');

            return false;
        }

        $this->warn('⚠ ' . $message);

        if (trim((string) $this->ask("Type '{$name}' to continue")) !== $name) {
            $this->comment('Cancelled.');

            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    protected function tables(): array
    {
        $option = $this->option('tables');

        if ($option === null || $option === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $option))));
    }
}
