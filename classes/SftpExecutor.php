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
}
