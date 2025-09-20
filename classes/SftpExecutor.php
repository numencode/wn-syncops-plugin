<?php namespace NumenCode\SyncOps\Classes;

use phpseclib3\Net\SFTP;

class SftpExecutor
{
    protected ?SFTP $sftp = null;
    protected string $server;
    protected array $config;
    protected mixed $credentials;

    public function __construct(string $server, array $config, mixed $credentials)
    {
        $this->server = $server;
        $this->config = $config;
        $this->credentials = $credentials;
    }

    public function connect(): SFTP
    {
        if (!$this->sftp) {
            $this->sftp = new SFTP($this->config['host'], $this->config['port'] ?? 22);

            if (!$this->sftp->login($this->config['username'], $this->credentials)) {
                throw new \RuntimeException("SFTP login failed for server: {$this->config['host']}");
            }
        }

        return $this->sftp;
    }

    public function upload(string $localFile, string $remoteFile): void
    {
        $sftp = $this->connect();

        if (!$sftp->put($remoteFile, file_get_contents($localFile))) {
            throw new \RuntimeException("[$this->server] Failed to upload file: $localFile -> $remoteFile");
        }
    }

    public function download(string $remoteFile, string $localFile): void
    {
        $sftp = $this->connect();

        if (!$sftp->get($remoteFile, $localFile)) {
            throw new \RuntimeException("[$this->server] Failed to download file: $remoteFile");
        }
    }

    /**
     * Recursively list files under $path on the remote server.
     *
     * Returns an array of full remote file paths (files only).
     * - Skips any path containing '/thumb/'.
     * - Keeps '.gitignore' files, skips other dotfiles.
     *
     * @param string $path Absolute or relative remote directory path
     * @return string[] remote file paths (absolute or relative matching $path param)
     */
    public function listFilesRecursively(string $path): array
    {
        $sftp = $this->connect();
        $path = rtrim($path, '/');

        $entries = $sftp->nlist($path, true);

        if (empty($entries)) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            // Normalize entry: remove leading './' and leading slashes
            $entry = preg_replace('#^\./#', '', $entry);
            $entry = ltrim($entry, '/');

            if ($entry === '' || $entry === '.' || $entry === '..') {
                continue;
            }

            // Some servers return 'dir/.' or 'dir/..' entries — ignore those
            if (str_ends_with($entry, '/.') || str_ends_with($entry, '/..')) {
                continue;
            }

            // Skip thumb and resized folders anywhere in the relative path
            if (stripos($entry, 'thumb/') !== false || stripos($entry, 'resized/') !== false) {
                continue;
            }

            $basename = basename($entry);

            // Keep .gitignore, but skip other dotfiles (e.g. .env)
            if ($basename !== '.gitignore' && str_starts_with($basename, '.')) {
                continue;
            }

            // Build full remote path
            $remoteFull = $path . '/' . $entry;

            // Ensure it's a regular file (phpseclib is_file)
            if ($sftp->is_file($remoteFull)) {
                $files[] = $remoteFull;
            }
        }

        return $files;
    }

    /**
     * Return remote file size in bytes or null on failure.
     */
    public function filesizeRemote(string $remoteFile): ?int
    {
        $sftp = $this->connect();
        $size = $sftp->filesize($remoteFile);

        if ($size === false) {
            return null;
        }

        return (int)$size;
    }

    /**
     * Convenience wrapper to check remote file/directory existence.
     */
    public function exists(string $remotePath): bool
    {
        $sftp = $this->connect();

        return $sftp->file_exists($remotePath);
    }
}
