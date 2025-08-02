<?php namespace NumenCode\SyncOps\Classes;

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class RemoteExecutor
{
    protected SSH2 $ssh;
    protected string $server;
    protected array $backup;
    public array $config;

    public function connect(string $server): bool
    {
        $this->server = $server;
        $this->config = config("syncops.connections.$server");

        if (!$this->config) {
            throw new \RuntimeException("SSH configuration not found for server: $server");
        }

        $this->backup = $this->config['backup'] ?? [];

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
        if (!isset($this->backup['path'])) {
            throw new \RuntimeException("Backup path not defined for [$this->server]");
        }

        $output = [];

        foreach ($commands as $command) {
            $cwd = rtrim($this->backup['path'], '/') . ($path ?? '');
            $fullCommand = "cd {$cwd} && {$command}";
            $result = $this->ssh->exec($fullCommand);
            $output[] = $result;

            if ($print) {
                echo $result;
            }
        }

        return implode("\n", $output);
    }

    public function runAndPrint(array $commands, ?string $path = null): void
    {
        $this->run($commands, true, $path);
    }

    public function hasNoUncommittedChanges(): bool
    {
        $result = $this->run(['git status --porcelain']);

        return trim($result) === '';
    }

    public function abortIfUncommittedChanges(): void
    {
        if (!$this->hasNoUncommittedChanges()) {
            $this->runAndPrint(['git status']);

            echo "\nRemote changes detected. Aborting deployment.\n";
            echo "Please run: php artisan project:pull {$this->server}\n";

            exit(1);
        }
    }


//    public function checkForChanges(bool $deploy = false): bool
//    {
//        $result = $this->run(['git status']);
//
//        if (str_contains($result, 'nothing to commit')) {
//            return true;
//        }
//
//        if ($deploy) {
//            $this->runAndPrint(['git status']);
//
//            echo "\nRemote changes detected. Aborting deployment.\n";
//            echo "Please run: php artisan syncops:project-pull {$this->server}\n";
//        }
//
//        return false;
//    }
}
