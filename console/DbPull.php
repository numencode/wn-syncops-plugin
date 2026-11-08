<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use Illuminate\Support\Facades\File;
use NumenCode\SyncOps\Classes\DatabaseDump;
use NumenCode\SyncOps\Classes\SyncOpsCommand;

class DbPull extends SyncOpsCommand
{
    protected $signature = 'syncops:db-pull
        {server        : The connection name from config/syncops.php}
        {--tables=     : Only these tables (comma-separated; overrides the connection\'s "tables")}
        {--i|no-import : Only download the dump into the backup folder, don\'t import it}
        {--keep        : Also keep the dump in the backup folder after importing it}
        {--timestamp=  : Date format for the dump file name}
        {--force       : Replace the local database without asking}';

    protected $description = 'Dump the database of a server, download it and import it into the local database.';

    protected function process(): int
    {
        $name = (string) $this->argument('server');
        $import = !$this->option('no-import');
        $local = $import ? DatabaseDump::localConnection() : null;

        if ($local !== null) {
            $this->redactor->add([(string) ($local['password'] ?? '')]);

            if (app()->environment('production')) {
                $this->warn('⚠ This is a PRODUCTION environment (APP_ENV=production).');
            }

            if (!$this->confirmOrForce("This replaces the local database '{$local['database']}' with the database of '{$name}'. Continue?")) {
                $this->comment('Cancelled.');

                return self::FAILURE;
            }
        }

        $server = $this->server($name);
        $remoteDatabase = $server->config->database['database'] ?? null;

        if ($remoteDatabase === null) {
            $this->error("✘ No database is configured for '{$name}' (database.database, database.username).");

            return self::FAILURE;
        }

        // --tables overrides the connection's "tables"
        $tables = $this->tables() ?: ($server->config->database['tables'] ?? []);

        $remoteTemp = $server->makeTempDirectory();
        $this->onCleanup(static fn () => $server->remove($remoteTemp));
        $localTemp = $this->localTempDirectory();

        $remoteDump = $this->step(
            "Dumping '{$remoteDatabase}' on '{$name}'" . ($tables !== [] ? ' (' . count($tables) . ' tables)' : ''),
            static fn () => DatabaseDump::dumpRemote($server, $remoteTemp, $tables)
        );

        $gzFile = $localTemp . '/dump.sql.gz';
        $size = $server->size($remoteDump) ?? 0;
        $this->step('Downloading the dump (' . $this->bytes($size) . ')', static fn () => $server->download($remoteDump, $gzFile));

        $fileName = $this->timestamp() . "_{$name}_{$remoteDatabase}.sql.gz";

        if (!$import) {
            $target = $this->backupDirectory('db-pull') . DIRECTORY_SEPARATOR . $fileName;
            File::move($gzFile, $target);
            @chmod($target, 0640);
            $this->info("✔ Dump saved to {$target}");

            return self::SUCCESS;
        }

        $sqlFile = $localTemp . '/dump.sql';
        $this->step('Decompressing', static fn () => DatabaseDump::gunzip($gzFile, $sqlFile));
        $this->step(
            "Importing into the local database '{$local['database']}'",
            static fn () => DatabaseDump::importLocal($local, $localTemp . '/client.cnf', $sqlFile)
        );

        if ($this->option('keep')) {
            $target = $this->backupDirectory('db-pull') . DIRECTORY_SEPARATOR . $fileName;
            File::copy($gzFile, $target);
            @chmod($target, 0640);
            $this->line("Dump kept in {$target}");
        }

        $this->newLine();
        $this->info("✔ The local database '{$local['database']}' now has the data of '{$name}'.");

        return self::SUCCESS;
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
