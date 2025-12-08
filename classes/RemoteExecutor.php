<?php namespace NumenCode\SyncOps\Classes;

use phpseclib3\Crypt\PublicKeyLoader;

class RemoteExecutor
{
    public array $config;
    public SshExecutor $ssh;
    public SftpExecutor $sftp;

    public function __construct(string $server)
    {
        $this->config = config("syncops.connections.$server");

        if (!$this->config) {
            throw new \RuntimeException("No config for server $server");
        }

        $credentials = isset($this->config['ssh']['password']) && empty($this->config['ssh']['key_path'])
            ? $this->config['ssh']['password']
            : PublicKeyLoader::load(file_get_contents($this->config['ssh']['key_path']));

        $this->ssh = new SshExecutor($server, $this->config, $credentials);
        $this->sftp = new SftpExecutor($server, $this->config, $credentials);
    }

    /**
     * Explicit way to establish both SSH and SFTP connections ahead of time.
     * You might use this if you want to fail early if either connection can’t be established
     * or you want both connections open at the same time (maybe to reuse them multiple times).
     *
     * @return void
     */
    public function connectBoth(): void
    {
        $this->ssh->connect();
        $this->sftp->connect();
    }
}
