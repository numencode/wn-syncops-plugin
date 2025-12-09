<?php namespace NumenCode\SyncOps\Classes;

use RuntimeException;
use phpseclib3\Crypt\PublicKeyLoader;

class RemoteExecutor
{
    public array $config;
    public SshExecutor $ssh;
    public SftpExecutor $sftp;

    public function __construct(string $server)
    {
        $config = config("syncops.connections.$server");

        if (!is_array($config) || $config === []) {
            throw new RuntimeException("No config for server {$server}");
        }

        $this->config = $config;

        $sshConfig = $this->config['ssh'] ?? null;

        if (!is_array($sshConfig)) {
            throw new RuntimeException("Missing SSH config for server {$server}");
        }

        $password = $sshConfig['password'] ?? null;
        $keyPath  = $sshConfig['key_path'] ?? null;

        if ($keyPath !== null && $keyPath !== '') {
            if (!is_readable($keyPath)) {
                throw new RuntimeException("SSH key file not found or unreadable at {$keyPath} for server {$server}");
            }

            $keyContents = file_get_contents($keyPath);

            if ($keyContents === false) {
                throw new RuntimeException("Unable to read SSH key file at {$keyPath} for server {$server}");
            }

            $credentials = PublicKeyLoader::load($keyContents);
        } elseif ($password !== null && $password !== '') {
            $credentials = $password;
        } else {
            throw new RuntimeException("No SSH password or key_path configured for server {$server}");
        }

        $this->ssh = new SshExecutor($server, $this->config, $credentials);
        $this->sftp = new SftpExecutor($server, $this->config, $credentials);
    }

    /**
     * Explicit way to establish both SSH and SFTP connections ahead of time.
     * You might use this if you want to fail early if either connection can’t be established
     * or you want both connections open at the same time (maybe to reuse them multiple times).
     */
    public function connectBoth(): void
    {
        $this->ssh->connect();
        $this->sftp->connect();
    }
}
