<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Classes;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Base class of the SyncOps console commands.
 *
 * Commands implement process(). Every error is printed with all configured secrets redacted, and the
 * cleanup callbacks (temporary files, maintenance mode, ...) always run, also after an error.
 */
abstract class SyncOpsCommand extends Command
{
    protected Redactor $redactor;

    /**
     * @var array<int, callable>
     */
    private array $cleanup = [];

    /**
     * @var array<string, RemoteServer>
     */
    private array $servers = [];

    abstract protected function process(): int;

    public function handle(): int
    {
        $this->redactor = new Redactor($this->localSecrets());
        $this->newLine();

        try {
            $result = $this->process();
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('✘ ' . $this->redactor->redact($e->getMessage()));
            $result = self::FAILURE;
        } finally {
            $this->runCleanup();
        }

        $this->newLine();

        return $result;
    }

    /**
     * Connects to a configured server (cached per command run). Without a configured host fingerprint, a
     * warning with the server's fingerprint is shown.
     */
    protected function server(string $name): RemoteServer
    {
        if (isset($this->servers[$name])) {
            return $this->servers[$name];
        }

        $config = ConnectionConfig::fromConfig($name);
        $this->redactor->add($config->secrets());

        /** @var RemoteServer $server */
        $server = app()->make(RemoteServer::class, ['config' => $config]);

        $this->line("Connecting to '{$name}' ({$config->username}@{$config->host})...");
        // Connects (and verifies the host key)
        $fingerprint = $server->fingerprint();

        if ($config->hostFingerprint === null) {
            $this->warn("⚠ The server's identity is not verified. Its host key fingerprint is {$fingerprint}.");
            $this->warn('  Add it to the connection (ssh.host_fingerprint), e.g. SYNCOPS_' . strtoupper($name) . "_HOST_FINGERPRINT={$fingerprint}");
        }

        return $this->servers[$name] = $server;
    }

    /**
     * Registers a callback that runs when the command ends, also after an error (in reverse order).
     */
    protected function onCleanup(callable $callback): void
    {
        array_unshift($this->cleanup, $callback);
    }

    /**
     * A new private local directory in storage/temp/syncops, removed when the command ends.
     */
    protected function localTempDirectory(): string
    {
        $directory = storage_path('temp/syncops/' . Str::random(12));
        File::makeDirectory($directory, 0700, true, true);
        $this->onCleanup(static fn () => File::deleteDirectory($directory));

        return $directory;
    }

    /**
     * The local folder for kept files (config "backup_path", default storage/backups), plus a subfolder.
     */
    protected function backupDirectory(?string $folder = null): string
    {
        $base = rtrim((string) (config('syncops.backup_path') ?: storage_path('backups')), '/\\');

        // Inside the project, backups must never end up in Git ("*" also ignores the .gitignore itself)
        if (!File::isDirectory($base)) {
            File::makeDirectory($base, 0755, true, true);

            if (str_starts_with(str_replace('\\', '/', $base), str_replace('\\', '/', base_path()))) {
                File::put($base . '/.gitignore', "*\n");
            }
        }

        $directory = $base . ($folder !== null && trim($folder, '/\\') !== '' ? '/' . trim($folder, '/\\') : '');

        $directory = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $directory);

        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        return $directory;
    }

    /**
     * File name prefix from the "timestamp" format (option --timestamp or config).
     */
    protected function timestamp(): string
    {
        $format = ($this->hasOption('timestamp') ? $this->option('timestamp') : null) ?: config('syncops.timestamp', 'Y-m-d_H_i_s');

        return now()->format((string) $format);
    }

    /**
     * Asks for confirmation unless --force is given; non-interactive runs need --force.
     */
    protected function confirmOrForce(string $question): bool
    {
        if ($this->hasOption('force') && $this->option('force')) {
            return true;
        }

        if (!$this->input->isInteractive()) {
            $this->error('✘ Confirmation needed: run the command interactively or add --force.');

            return false;
        }

        return $this->confirm($question, false);
    }

    /**
     * A callback that prints remote output as it arrives (for RemoteServer::run()).
     */
    protected function liveOutput(): callable
    {
        return function (string $chunk): void {
            $this->output->write($this->redactor->redact($chunk));
        };
    }

    /**
     * Runs a step, prints its label and duration, and returns the step's result.
     */
    protected function step(string $label, callable $callback): mixed
    {
        $this->line($label . '...');
        $start = microtime(true);
        $result = $callback();
        $this->comment('  done in ' . $this->duration(microtime(true) - $start));

        return $result;
    }

    /**
     * Whether a media file (path relative to storage/app) is skipped by media-pull and media-backup:
     * any folder named in "media.exclude", and files starting with a dot (.gitignore files belong to the
     * Git repository, not to the media).
     */
    protected function isExcludedMedia(string $relativePath): bool
    {
        $segments = explode('/', str_replace('\\', '/', $relativePath));
        $file = (string) array_pop($segments);
        $excluded = (array) config('syncops.media.exclude', ['resized', 'thumb', 'temp']);

        if (str_starts_with($file, '.')) {
            return true;
        }

        return array_intersect($segments, $excluded) !== [];
    }

    /**
     * The cloud storage disk, with a clear error when it is not configured.
     */
    protected function cloudDisk(string $disk): \Illuminate\Contracts\Filesystem\Filesystem
    {
        try {
            return \Illuminate\Support\Facades\Storage::disk($disk);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException("The storage disk '{$disk}' is not configured (config/filesystems.php).");
        }
    }

    /**
     * Uploads a local file to a cloud disk as a stream (large files are not loaded into memory).
     */
    protected function uploadFile(string $disk, string $localFile, string $path): void
    {
        $stream = @fopen($localFile, 'rb');

        if ($stream === false) {
            throw new \RuntimeException("Unable to read {$localFile}.");
        }

        try {
            if ($this->cloudDisk($disk)->writeStream($path, $stream) === false) {
                throw new \RuntimeException("Upload to '{$disk}' ({$path}) failed.");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Number of backups to keep (option --keep, else config "backup_keep"); null keeps all.
     */
    protected function keepCount(): ?int
    {
        $keep = ($this->hasOption('keep') ? $this->option('keep') : null) ?? config('syncops.backup_keep');

        if ($keep === null || $keep === '' || $keep === false) {
            return null;
        }

        if (!is_numeric($keep) || (int) $keep < 1) {
            throw new \RuntimeException('--keep (and backup_keep) must be a number of 1 or more.');
        }

        return (int) $keep;
    }

    /**
     * Deletes all but the newest $keep backups (files ending with $suffix) in a local folder and, with a
     * disk, in a cloud folder.
     */
    protected function pruneBackups(?int $keep, string $suffix, string $localDirectory, ?string $disk = null, string $cloudFolder = ''): void
    {
        if ($keep === null) {
            return;
        }

        $local = [];

        foreach (File::files($localDirectory) as $file) {
            if (str_ends_with($file->getFilename(), $suffix)) {
                $local[$file->getPathname()] = $file->getMTime();
            }
        }

        foreach ($this->oldest($local, $keep) as $path) {
            File::delete($path);
            $this->comment('  Deleted old local backup ' . basename($path));
        }

        if ($disk === null) {
            return;
        }

        $storage = $this->cloudDisk($disk);
        $cloud = [];

        foreach ($storage->files(trim($cloudFolder, '/')) as $path) {
            if (str_ends_with($path, $suffix)) {
                $cloud[$path] = $storage->lastModified($path);
            }
        }

        foreach ($this->oldest($cloud, $keep) as $path) {
            $storage->delete($path);
            $this->comment("  Deleted old backup {$path} from '{$disk}'");
        }
    }

    /**
     * @param array<string, int> $files path => modification time
     * @return array<int, string> the files beyond the newest $keep
     */
    private function oldest(array $files, int $keep): array
    {
        // Newest first; the name (timestamp) decides between equal times
        uksort($files, static fn (string $a, string $b) => [$files[$b], $b] <=> [$files[$a], $a]);

        return array_slice(array_keys($files), $keep);
    }

    /**
     * Runs a local Git command in the project and returns its output.
     *
     * @param array<int, string> $arguments
     */
    protected function git(array $arguments): string
    {
        $process = new \Symfony\Component\Process\Process(['git', ...$arguments], base_path());
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('git ' . $arguments[0] . " failed:\n" . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process->getOutput();
    }

    protected function duration(float $seconds): string
    {
        return $seconds < 60 ? round($seconds, 1) . ' s' : floor($seconds / 60) . ' min ' . round(fmod($seconds, 60)) . ' s';
    }

    protected function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }

    /**
     * Secrets of the local configuration (database passwords) that must never be printed.
     *
     * @return array<int, string>
     */
    protected function localSecrets(): array
    {
        $secrets = [];

        foreach ((array) config('database.connections', []) as $connection) {
            if (is_array($connection) && !empty($connection['password']) && is_string($connection['password'])) {
                $secrets[] = $connection['password'];
            }
        }

        return $secrets;
    }

    private function runCleanup(): void
    {
        foreach ($this->cleanup as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $this->warn('⚠ Cleanup: ' . $this->redactor->redact($e->getMessage()));
            }
        }

        $this->cleanup = [];

        foreach ($this->servers as $server) {
            $server->disconnect();
        }

        $this->servers = [];
    }
}
