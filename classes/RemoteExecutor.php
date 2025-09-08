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

        $this->ssh = new SSH2($this->config['host'], $this->config['port'] ?? 22);

        $credentials = isset($this->config['password'])
            ? $this->config['password']
            : PublicKeyLoader::load(file_get_contents($this->config['key_path']));

        if (!$this->ssh->login($this->config['user'], $credentials)) {
            throw new \RuntimeException("SSH login failed for server: {$server}.");
        }

        return true;
    }

    /**
     * Securely executes multiple commands on the remote server.
     * Each command must be an array of arguments.
     *
     * @param array $commands An array of commands, e.g., [['git', 'status'], ['ls', '-la']]
     * @param bool $print Whether to echo the output in real-time.
     * @return string The combined output of all commands.
     */
    public function runCommands(array $commands, bool $print = false): string
    {
        if (!isset($this->config['path'])) {
            throw new \RuntimeException("Path is not defined for [$this->server]");
        }

        $output = [];
        $basePath = $this->config['path'];

        foreach ($commands as $commandParts) {
            $result = $this->executeSecureCommand($commandParts, $basePath);
            $output[] = $result;

            if ($print) {
                echo $result;
            }
        }

        return implode("\n", $output);
    }

    /**
     * A secure wrapper for runCommands that prints output.
     *
     * @param array $commands An array of commands, e.g., [['git', 'add', '--all']]
     * @return string The combined, trimmed output.
     */
    public function runAndPrint(array $commands): string
    {
        return trim($this->runCommands($commands, true));
    }

    /**
     * A secure wrapper to run a single command and get its output.
     *
     * @param array $command A single command as an array, e.g., ['git', 'status']
     * @return string The trimmed output.
     */
    public function runAndGet(array $command): string
    {
        return trim($this->runCommands([$command], false));
    }

    public function remoteIsClean(): bool
    {
        $result = $this->runAndGet(['git', 'status', '--porcelain']);
        return $result === '';
    }

    /**
     * Executes a single, secure command on the remote server.
     *
     * @param array $commandParts The command and its arguments.
     * @param string $cwd The working directory to run the command in.
     * @return string The command's standard output.
     * @throws \RuntimeException on command failure.
     */
    private function executeSecureCommand(array $commandParts, string $cwd): string
    {
        $escapedParts = array_map('escapeshellarg', $commandParts);
        $safeCommand = implode(' ', $escapedParts);
        $fullCommand = "cd " . escapeshellarg(str_replace('~', '$HOME', $cwd)) . " && {$safeCommand}";

        $output = $this->ssh->exec($fullCommand);

        if ($this->ssh->getExitStatus() !== 0) {
            throw new \RuntimeException(
                "Remote command failed on [{$this->server}]:\n" .
                "Command: {$safeCommand}\n" .
                "Error: " . $this->ssh->getStdError()
            );
        }

        return $output;
    }

//    use phpseclib3\Net\SFTP; // Add this at the top
//
//    public function getSftp(): SFTP
//    {
//        return new SFTP($this->config['host']);
//    }
}
