<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use Illuminate\Support\Facades\File;
use NumenCode\SyncOps\Classes\DatabaseDump;
use NumenCode\SyncOps\Classes\SyncOpsCommand;

class DbBackup extends SyncOpsCommand
{
    protected $signature = 'syncops:db-backup
        {cloud?        : Storage disk to upload the dump to (e.g. "dropbox"); without it the dump stays local}
        {--folder=database : Folder in the backup folder and on the cloud disk}
        {--connection= : Local database connection (default: the default connection)}
        {--timestamp=  : Date format for the dump file name}
        {--g|no-gzip   : Don\'t compress the dump}
        {--d|no-delete : Keep the local dump after uploading it}
        {--keep=       : Keep only the newest N backups in the folder (locally and on the cloud disk)}';

    protected $description = 'Back up the local database (dump, optionally upload to cloud storage and rotate old backups).';

    protected function process(): int
    {
        $cloud = $this->argument('cloud') ? (string) $this->argument('cloud') : null;
        $folder = trim((string) $this->option('folder'), '/');
        $gzip = !$this->option('no-gzip');
        $keep = $this->keepCount();
        $connection = DatabaseDump::localConnection($this->option('connection') ?: null);
        $this->redactor->add([(string) ($connection['password'] ?? '')]);

        if ($cloud !== null) {
            $this->cloudDisk($cloud); // fail early when the disk is not configured
        }

        $suffix = $gzip ? '.sql.gz' : '.sql';
        $fileName = $this->timestamp() . '_' . $connection['database'] . $suffix;
        $backupDirectory = $this->backupDirectory($folder);
        $keepLocal = $cloud === null || $this->option('no-delete');
        $localFile = ($keepLocal ? $backupDirectory : $this->localTempDirectory()) . DIRECTORY_SEPARATOR . $fileName;

        $this->step(
            "Dumping the local database '{$connection['database']}'",
            fn () => DatabaseDump::dumpLocal($connection, $this->localTempDirectory() . '/client.cnf', $localFile, $gzip)
        );
        @chmod($localFile, 0640);
        $size = (int) filesize($localFile);

        if ($cloud !== null) {
            $path = ($folder !== '' ? $folder . '/' : '') . $fileName;
            $this->step("Uploading to '{$cloud}' ({$path}, " . $this->bytes($size) . ')', fn () => $this->uploadFile($cloud, $localFile, $path));
        }

        // Only backups of this database (the folder may hold others)
        $this->pruneBackups($keep, "_{$connection['database']}{$suffix}", $backupDirectory, $cloud, $folder);

        $this->newLine();
        $this->info('✔ Database backup created (' . $this->bytes($size) . ')'
            . ($cloud !== null ? " and uploaded to '{$cloud}'" : '')
            . ($keepLocal ? ': ' . $localFile : '.'));

        return self::SUCCESS;
    }
}
