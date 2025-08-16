<?php namespace NumenCode\SyncOps\Classes;

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class RemoteExecutor
{
    protected SSH2 $ssh;
    protected string $server;
    public array $config;

    public function connect(string $server): bool
    {
        $this->server = $server;
        $this->config = config("syncops.connections.$server");

        if (!$this->config) {
            throw new \RuntimeException("SSH configuration not found for server: $server");
        }

        $this->ssh = new SSH2(
            $this->config['host'],
            $this->config['port'] ?? 22
        );

        if (isset($this->config['password'])) {
            if (!$this->ssh->login($this->config['username'], $this->config['password'])) {
                throw new \RuntimeException("SSH login failed using password.");
            }
        } elseif (isset($this->config['key_path'])) {
            $key = PublicKeyLoader::load(file_get_contents($this->config['key_path']));

            if (!$this->ssh->login($this->config['username'], $key)) {
                throw new \RuntimeException("SSH login failed using private key.");
            }
        } else {
            throw new \InvalidArgumentException("SSH credentials not provided.");
        }

        return true;
    }

    public function run(array $commands, bool $print = false, ?string $path = null): string
    {
        if (!isset($this->config['path'])) {
            throw new \RuntimeException("Path is not defined for [$this->server]");
        }

        $output = [];

        foreach ($commands as $command) {
            $cwd = rtrim($this->config['path'], '/') . ($path ?? '');
            $fullCommand = "cd {$cwd} && {$command}";
            $result = $this->ssh->exec($fullCommand);
            $output[] = $result;

            if ($print) {
                echo $result;
            }
        }

        return implode("\n", $output);
    }

    public function runAndPrint(array $commands, ?string $path = null): string
    {
        return trim($this->run($commands, true, $path));
    }

    public function runAndGet(string $command, ?string $path = null): string
    {
        return trim($this->run([$command], false, $path));
    }

    public function remoteIsClean(): bool
    {
        $result = $this->run(['git status --porcelain']);

        return trim($result) === '';
    }

    public function abortIfRemoteHasChanges(): void
    {
        if (!$this->remoteIsClean()) {
            $status = $this->run(['git status']);

            throw new \RuntimeException(
                "Remote changes detected on [{$this->server}].\n\n" .
                "Git status:\n{$status}\n" .
                "Please run: php artisan syncops:project-pull {$this->server}"
            );
        }
    }
}
