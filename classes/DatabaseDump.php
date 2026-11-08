<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Classes;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * MySQL/MariaDB dumps and imports without passwords on command lines.
 *
 * Credentials are written to a temporary option file ("[client]" group) in a private directory and passed
 * with --defaults-extra-file, so they never appear in process lists, shell history or error messages.
 */
final class DatabaseDump
{
    /**
     * Consistent dump without locking (InnoDB), streamed row by row, without the PROCESS privilege that
     * MySQL 8 requires for tablespaces, and without comments (stable output).
     */
    public const DUMP_OPTIONS = ['--single-transaction', '--quick', '--no-tablespaces', '--skip-comments'];

    /**
     * Contents of an option file with the connection credentials.
     *
     * @param array<string, mixed> $database username, password, host, port, unix_socket
     */
    public static function optionFile(array $database): string
    {
        $lines = ['[client]'];
        $map = ['user' => 'username', 'password' => 'password', 'host' => 'host', 'port' => 'port', 'socket' => 'unix_socket'];

        foreach ($map as $option => $key) {
            $value = $database[$key] ?? null;

            if ($value !== null && $value !== '') {
                $lines[] = $option . '="' . addcslashes((string) $value, "\\\"") . '"';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Dumps the server's database (connection "database" block) into a gzip file in $tempDirectory (a private
     * directory from RemoteServer::makeTempDirectory()) and returns the remote path of the dump.
     *
     * @param array<int, string> $tables only these tables (all when empty)
     */
    public static function dumpRemote(RemoteServer $server, string $tempDirectory, array $tables = []): string
    {
        $database = $server->config->database;

        if ($database === null) {
            throw new RuntimeException("No database is configured for '{$server->config->name}' (database.database, database.username).");
        }

        $optionFile = $tempDirectory . '/client.cnf';
        $output = $tempDirectory . '/dump.sql.gz';
        $server->putContents($optionFile, static::optionFile($database));

        // MariaDB 11 deprecates the "mysqldump" name; use "mariadb-dump" where it exists
        $client = trim($server->runShell('command -v mariadb-dump || command -v mysqldump', null, false)
            ->throwIfFailed('Finding mariadb-dump/mysqldump on the server')->output);

        $command = RemoteServer::escape([
            $client,
            '--defaults-extra-file=' . $optionFile,
            ...self::DUMP_OPTIONS,
            $database['database'],
            ...$tables,
        ]);

        $server->runShell('set -o pipefail; ' . $command . ' | gzip > ' . RemoteServer::quote($output), null, false)
            ->throwIfFailed("Dumping the database '{$database['database']}' on the server");

        return $output;
    }

    /**
     * Dumps a local database into a gzip file (or a plain .sql file with $gzip = false).
     *
     * @param array<string, mixed> $database a Laravel connection config
     * @param array<int, string> $tables
     */
    public static function dumpLocal(array $database, string $optionFile, string $file, bool $gzip = true, array $tables = [], ?int $timeout = null): void
    {
        file_put_contents($optionFile, static::optionFile($database));
        @chmod($optionFile, 0600);

        $handle = $gzip ? @gzopen($file, 'wb6') : @fopen($file, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to write {$file}.");
        }

        $process = new Process([
            static::binary('mysqldump'),
            '--defaults-extra-file=' . $optionFile,
            ...self::DUMP_OPTIONS,
            (string) $database['database'],
            ...$tables,
        ]);
        $process->setTimeout($timeout);

        try {
            $process->run(function (string $type, string $buffer) use ($handle, $gzip): void {
                if ($type === Process::OUT) {
                    $gzip ? gzwrite($handle, $buffer) : fwrite($handle, $buffer);
                }
            });
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException("Dumping the local database '{$database['database']}' failed:\n" . trim($process->getErrorOutput()));
        }
    }

    /**
     * Imports an SQL file into a local database.
     *
     * @param array<string, mixed> $database a Laravel connection config
     */
    public static function importLocal(array $database, string $optionFile, string $sqlFile, ?int $timeout = null): void
    {
        file_put_contents($optionFile, static::optionFile($database));
        @chmod($optionFile, 0600);

        $input = @fopen($sqlFile, 'rb');

        if ($input === false) {
            throw new RuntimeException("Unable to read {$sqlFile}.");
        }

        $process = new Process([static::binary('mysql'), '--defaults-extra-file=' . $optionFile, (string) $database['database']]);
        $process->setInput($input);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException("Importing into the local database '{$database['database']}' failed:\n" . trim($process->getErrorOutput()));
        }
    }

    /**
     * Decompresses a gzip dump in chunks. MariaDB's "sandbox mode" first line (/*M!999999…) is dropped,
     * because older MariaDB and MySQL clients reject it.
     */
    public static function gunzip(string $gzFile, string $sqlFile): void
    {
        $in = @gzopen($gzFile, 'rb');
        $out = @fopen($sqlFile, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException("Unable to decompress {$gzFile}.");
        }

        try {
            $first = gzgets($in);

            if (is_string($first) && !str_starts_with($first, '/*M!999999')) {
                fwrite($out, $first);
            }

            while (!gzeof($in)) {
                $chunk = gzread($in, 1048576);

                if ($chunk === false) {
                    throw new RuntimeException("Unable to read {$gzFile}.");
                }

                fwrite($out, $chunk);
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }

    /**
     * The local database connection (Laravel config), which must be MySQL or MariaDB.
     *
     * @return array<string, mixed>
     */
    public static function localConnection(?string $name = null): array
    {
        $name ??= (string) config('database.default');
        $connection = config('database.connections.' . $name);

        if (!is_array($connection) || empty($connection['database'])) {
            throw new RuntimeException("The local database connection '{$name}' is not configured.");
        }

        if (!in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("The local database connection '{$name}' uses '{$connection['driver']}'; SyncOps supports MySQL and MariaDB.");
        }

        return $connection;
    }

    /**
     * Local client binary: config "local.mysql" / "local.mysqldump" (default: from the PATH).
     */
    public static function binary(string $name): string
    {
        return (string) (config('syncops.local.' . $name) ?: $name);
    }
}
