<?php namespace NumenCode\SyncOps\Support;

class MysqlCommandBuilder
{
    public static function dump(array $config, string $outputFile, bool $gzip = true, array $tables = []): string
    {
        $user = escapeshellarg($config['username']);
        $pass = "'" . str_replace("'", "'\\''", $config['password']) . "'";
        $db = escapeshellarg($config['database']);
        $limitTables = implode(' ', array_map('escapeshellarg', $tables));

        $command = "mysqldump --skip-comments --replace -u{$user} -p{$pass} {$db} {$limitTables}";

        if ($gzip) {
            $command .= " | gzip > " . escapeshellarg($outputFile);
        } else {
            $command .= " > " . escapeshellarg($outputFile);
        }

        return $command;
    }

    public static function import(array $dbConfig, string $filePath): string
    {
        $user = $dbConfig['username'];
        $pass = $dbConfig['password'];
        $db = $dbConfig['database'] . 'test';
        $filePath = str_replace('\\', '/', $filePath); // Normalize Windows paths

        return sprintf(
            'mysql -u%s -p%s %s < %s',
            $user,
            $pass,
            escapeshellarg($db),
            str_replace('\\', '/', $filePath)
        );
    }
}
