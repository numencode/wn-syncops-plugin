<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Classes;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use RuntimeException;

/**
 * One SSH connection to a configured server, used for both commands and file transfers
 * (phpseclib's SFTP class extends SSH2, so a single login is enough).
 *
 * Commands run in the project path. Use run() with an argument array (every part is escaped) and
 * runShell() only for trusted scripts that need shell features (pipes, redirects, variables).
 */
class RemoteServer
{
    /**
     * SFTP file type of regular files (NET_SFTP_TYPE_REGULAR in phpseclib, which defines its constants only once it is used).
     */
    protected const SFTP_TYPE_REGULAR = 1;

    protected ?SFTP $sftp = null;

    /**
     * The server's host key fingerprint ("SHA256:…"), known after connecting.
     */
    protected ?string $fingerprint = null;

    public function __construct(public readonly ConnectionConfig $config)
    {
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Connects and logs in. The host key is verified against "ssh.host_fingerprint" before the credentials
     * are sent; without a configured fingerprint the connection is made and the fingerprint can be read
     * with fingerprint() (and shown as a warning).
     */
    public function connect(): SFTP
    {
        if ($this->sftp !== null) {
            return $this->sftp;
        }

        $config = $this->config;
        $sftp = new SFTP($config->host, $config->port, 15);

        $hostKey = $sftp->getServerPublicHostKey();

        if (!is_string($hostKey)) {
            throw new RuntimeException("Unable to connect to {$config->host}:{$config->port} ('{$config->name}').");
        }

        $this->fingerprint = static::fingerprintOf($hostKey);

        if ($config->hostFingerprint !== null && !hash_equals($config->hostFingerprint, $this->fingerprint)) {
            $sftp->disconnect();

            throw new RuntimeException(
                "Host key verification failed for '{$config->name}' ({$config->host}): the server presented "
                . "{$this->fingerprint}, but {$config->hostFingerprint} is configured. The server's key changed, or "
                . 'someone is intercepting the connection. Not connecting.'
            );
        }

        if (!$sftp->login($config->username, $this->credentials())) {
            throw new RuntimeException("SSH login failed for '{$config->name}' ({$config->username}@{$config->host}).");
        }

        $sftp->setTimeout($config->timeout);
        $sftp->enableQuietMode();

        return $this->sftp = $sftp;
    }

    public function disconnect(): void
    {
        $this->sftp?->disconnect();
        $this->sftp = null;
    }

    /**
     * The server's host key fingerprint, e.g. "SHA256:abc…" (connects if needed).
     */
    public function fingerprint(): string
    {
        $this->connect();

        return (string) $this->fingerprint;
    }

    /**
     * OpenSSH-style SHA-256 fingerprint of a public host key ("ssh-ed25519 AAAA…").
     */
    public static function fingerprintOf(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));
        $blob = base64_decode($parts[1] ?? $parts[0], true);

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string) $blob, true)), '=');
    }

    /**
     * Runs a command (argument array, every part escaped) in the project path.
     *
     * @param array<int, string> $command e.g. ['git', 'status', '--porcelain']
     * @param callable|null $onOutput receives the output while the command runs (stdout and stderr together)
     */
    public function run(array $command, ?callable $onOutput = null): CommandResult
    {
        return $this->runShell(static::escape($command), $onOutput);
    }

    /**
     * Runs a trusted shell script in the project path.
     *
     * @param callable|null $onOutput receives the output while the command runs (stdout and stderr together)
     */
    public function runShell(string $script, ?callable $onOutput = null, bool $inProject = true): CommandResult
    {
        $sftp = $this->connect();
        $command = ($inProject ? $this->cdProject() . ' && ' : '') . $script;

        if ($onOutput !== null) {
            // Live output: stderr is merged into the stream (e.g. Composer writes its progress to stderr)
            $output = '';
            $sftp->exec('{ ' . $command . '; } 2>&1', function (string $chunk) use ($onOutput, &$output): void {
                $output .= $chunk;
                $onOutput($chunk);
            });
            $error = '';
        } else {
            $output = (string) $sftp->exec($command);
            $error = (string) $sftp->getStdError();
        }

        if ($sftp->isTimeout()) {
            // A timed-out channel stays open and breaks every later command; start over next time
            $this->disconnect();

            throw new RuntimeException(
                "A remote command on '{$this->config->name}' did not finish within {$this->config->timeout} seconds "
                . "(ssh.timeout; 0 = no limit)."
            );
        }

        $exitCode = $sftp->getExitStatus();

        return new CommandResult(is_int($exitCode) ? $exitCode : 1, $output, $error);
    }

    /**
     * The configured PHP binary as an argument array (e.g. ['php8.4']).
     *
     * @return array<int, string>
     */
    public function php(): array
    {
        return static::split($this->config->php);
    }

    /**
     * The configured Composer command as an argument array (e.g. ['php8.4', '/usr/bin/composer']).
     *
     * @return array<int, string>
     */
    public function composer(): array
    {
        return static::split($this->config->composer);
    }

    /**
     * Absolute remote path of a file in the project (handles "~" in the project path).
     */
    public function projectPath(string $relative = ''): string
    {
        $path = $this->config->path;

        if ($path === '~' || str_starts_with($path, '~/')) {
            $home = trim($this->runShell('printf %s "$HOME"', null, false)->throwIfFailed('Reading $HOME')->output);
            $path = $home . substr($path, 1);
        }

        return rtrim($path, '/') . ($relative !== '' ? '/' . ltrim($relative, '/') : '');
    }

    /**
     * Creates a private temporary directory (mode 0700) on the server and returns its path.
     */
    public function makeTempDirectory(): string
    {
        $base = $this->config->tempPath !== null ? static::quote($this->config->tempPath) : '"${TMPDIR:-/tmp}"';
        $result = $this->runShell('mktemp -d -p ' . $base . ' syncops.XXXXXXXX', null, false)
            ->throwIfFailed('Creating a temporary directory on the server');

        return trim($result->output);
    }

    /**
     * Removes a file or directory on the server (used for SyncOps' own temporary files).
     */
    public function remove(string $path): void
    {
        $this->runShell('rm -rf -- ' . static::quote($path), null, false)->throwIfFailed("Removing {$path}");
    }

    public function upload(string $localFile, string $remoteFile): void
    {
        $stream = @fopen($localFile, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to read the local file {$localFile}.");
        }

        try {
            if (!$this->connect()->put($remoteFile, $stream)) {
                throw new RuntimeException("Upload to {$remoteFile} failed.");
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Writes a string to a remote file (e.g. a credentials file inside a private temporary directory).
     */
    public function putContents(string $remoteFile, string $contents): void
    {
        if (!$this->connect()->put($remoteFile, $contents)) {
            throw new RuntimeException("Writing {$remoteFile} failed.");
        }
    }

    public function download(string $remoteFile, string $localFile): void
    {
        if (!$this->connect()->get($remoteFile, $localFile)) {
            throw new RuntimeException("Download of {$remoteFile} failed.");
        }
    }

    public function size(string $remoteFile): ?int
    {
        $size = $this->connect()->filesize($remoteFile);

        return is_int($size) ? $size : null;
    }

    /**
     * Lists all regular files below a remote directory with one recursive listing.
     *
     * @return array<string, int> relative path => size
     */
    public function listFiles(string $directory): array
    {
        $list = $this->connect()->rawlist(rtrim($directory, '/'), true);

        if (!is_array($list)) {
            throw new RuntimeException("Unable to list {$directory} on the server.");
        }

        $files = [];
        static::flattenListing($list, '', $files);
        ksort($files);

        return $files;
    }

    /**
     * Escapes a command given as an argument array.
     *
     * @param array<int, string> $command
     */
    public static function escape(array $command): string
    {
        return implode(' ', array_map(static fn ($part) => static::quote((string) $part), $command));
    }

    /**
     * Quotes one argument for the server's POSIX shell. PHP's static::quote() can't be used: on Windows it
     * quotes for cmd.exe (double quotes, and it replaces ", % and !), which breaks commands on a Linux server.
     */
    public static function quote(string $argument): string
    {
        return "'" . str_replace("'", "'\''", $argument) . "'";
    }

    /**
     * Splits a configured binary like "php8.4 /usr/bin/composer" into arguments.
     *
     * @return array<int, string>
     */
    public static function split(string $command): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($command)) ?: [], static fn ($part) => $part !== ''));
    }

    /**
     * "cd" into the project path; a leading "~" is expanded by the shell.
     */
    protected function cdProject(): string
    {
        $path = $this->config->path;

        if ($path === '~') {
            return 'cd "$HOME"';
        }

        if (str_starts_with($path, '~/')) {
            return 'cd "$HOME"/' . static::quote(substr($path, 2));
        }

        return 'cd ' . static::quote($path);
    }

    /**
     * @return mixed a password or a private key
     */
    protected function credentials(): mixed
    {
        $config = $this->config;

        if ($config->keyPath !== null) {
            $contents = @file_get_contents($config->keyPath);

            if ($contents === false) {
                throw new RuntimeException("Unable to read the SSH key {$config->keyPath} ('{$config->name}').");
            }

            try {
                return PublicKeyLoader::load($contents, $config->keyPassphrase ?? false);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    "Unable to load the SSH key {$config->keyPath}"
                    . ($config->keyPassphrase === null ? ' (is it encrypted? set ssh.key_passphrase)' : ' (wrong passphrase?)')
                    . '.'
                );
            }
        }

        return (string) $config->password;
    }

    /**
     * @param array<string, mixed> $list
     * @param array<string, int> $files
     */
    protected static function flattenListing(array $list, string $prefix, array &$files): void
    {
        foreach ($list as $name => $entry) {
            $name = (string) $name;

            if ($name === '.' || $name === '..') {
                continue;
            }

            if (is_array($entry)) {
                static::flattenListing($entry, $prefix . $name . '/', $files);
            } elseif (is_object($entry) && (int) ($entry->type ?? 0) === self::SFTP_TYPE_REGULAR) {
                $files[$prefix . $name] = (int) ($entry->size ?? 0);
            }
        }
    }
}
