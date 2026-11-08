<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use NumenCode\SyncOps\Classes\SyncOpsCommand;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProjectBackup extends SyncOpsCommand
{
    protected $signature = 'syncops:project-backup
        {cloud?          : Storage disk to upload the archive to (e.g. "dropbox"); without it the archive stays local}
        {--folder=project : Folder in the backup folder and on the cloud disk}
        {--exclude=      : More paths to leave out (comma-separated, relative to the project root)}
        {--include-env   : Include the .env file (it contains all secrets)}
        {--timestamp=    : Date format for the archive file name}
        {--d|no-delete   : Keep the local archive after uploading it}
        {--keep=         : Keep only the newest N archives in the folder (locally and on the cloud disk)}';

    protected $description = 'Back up the project files as a .tar.gz archive, optionally upload it to cloud storage and rotate old archives.';

    protected function process(): int
    {
        $cloud = $this->argument('cloud') ? (string) $this->argument('cloud') : null;
        $folder = trim((string) $this->option('folder'), '/');
        $keep = $this->keepCount();

        if ($cloud !== null) {
            $this->cloudDisk($cloud); // fail early when the disk is not configured
        }

        $fileName = $this->timestamp() . '.tar.gz';
        $backupDirectory = $this->backupDirectory($folder);
        $keepLocal = $cloud === null || $this->option('no-delete');
        $archive = ($keepLocal ? $backupDirectory : $this->localTempDirectory()) . DIRECTORY_SEPARATOR . $fileName;
        $excludes = $this->excludes();

        $this->step('Creating the archive (' . count($excludes) . ' paths excluded' . ($this->option('include-env') ? ', with .env' : ', without .env') . ')', function () use ($archive, $excludes): void {
            // tar writes to stdout and PHP writes the file: archive paths like "C:\…" confuse GNU tar on Windows
            // (it reads "C:" as a remote host), and this works the same with every tar
            $command = ['tar', '-cz', '-f', '-'];

            foreach ($excludes as $path) {
                // "./path" only matches at the project root
                $command[] = '--exclude=./' . $path;
            }

            $command[] = '.';

            $handle = @fopen($archive, 'wb');

            if ($handle === false) {
                throw new RuntimeException("Unable to write {$archive}.");
            }

            $process = new Process($command, base_path());
            $process->setTimeout(null);

            try {
                $process->run(static function (string $type, string $buffer) use ($handle): void {
                    if ($type === Process::OUT) {
                        fwrite($handle, $buffer);
                    }
                });
            } finally {
                fclose($handle);
            }

            if (!$process->isSuccessful()) {
                @unlink($archive);

                throw new RuntimeException('Creating the archive failed:' . "\n" . trim($process->getErrorOutput()));
            }
        });

        @chmod($archive, 0640);
        $size = (int) filesize($archive);

        if ($cloud !== null) {
            $path = ($folder !== '' ? $folder . '/' : '') . $fileName;
            $this->step("Uploading to '{$cloud}' ({$path}, " . $this->bytes($size) . ')', fn () => $this->uploadFile($cloud, $archive, $path));
        }

        $this->pruneBackups($keep, '.tar.gz', $backupDirectory, $cloud, $folder);

        $this->newLine();
        $this->info('✔ Project backup created (' . $this->bytes($size) . ')'
            . ($cloud !== null ? " and uploaded to '{$cloud}'" : '')
            . ($keepLocal ? ': ' . $archive : '.'));

        return self::SUCCESS;
    }

    /**
     * Paths left out of the archive: config "backup.exclude", the backup folder, --exclude and (unless
     * --include-env) the .env file.
     *
     * @return array<int, string>
     */
    protected function excludes(): array
    {
        $paths = (array) config('syncops.backup.exclude', []);
        $paths[] = $this->relativeToProject($this->backupDirectory());

        foreach (explode(',', (string) $this->option('exclude')) as $path) {
            $paths[] = $path;
        }

        if (!$this->option('include-env')) {
            $paths[] = '.env';
        }

        $paths = array_map(static fn ($path) => trim(str_replace('\\', '/', (string) $path), "/ \t"), $paths);

        return array_values(array_unique(array_filter($paths, static fn ($path) => $path !== '' && $path !== '.')));
    }

    protected function relativeToProject(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : '';
    }
}
