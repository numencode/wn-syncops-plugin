<?php namespace NumenCode\SyncOps\Classes;

use phpseclib3\Net\SSH2;

class SshExecutor
{
    protected ?SSH2 $ssh = null;
    protected string $server;
    protected array $config;
    protected mixed $credentials;

    public function __construct(string $server, array $config, mixed $credentials)
    {
        $this->server = $server;
        $this->config = $config;
        $this->credentials = $credentials;
    }

    public function connect(): SSH2
    {
        if (!$this->ssh) {
            $this->ssh = new SSH2($this->config['host'], $this->config['port'] ?? 22);

            if (!$this->ssh->login($this->config['username'], $this->credentials)) {
                throw new \RuntimeException("SSH login failed for server: {$this->config['host']}");
            }
        }

        return $this->ssh;
    }

    /**
     * Determines whether the remote Git working directory has no uncommitted changes.
     *
     * @return bool True if the repository is clean, false if there are staged or un-staged changes.
     */
    public function remoteIsClean(): bool
    {
        return $this->runAndGet(['git', 'status', '--porcelain']) === '';
    }

    /**
     * Execute command on the remote server.
     *
     * @param string $command A single command.
     * @return string The trimmed output.
     */
    public function exec(string $command): string
    {
        $this->connect();
        return $this->ssh->exec($command);
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
            throw new \RuntimeException("Path is not defined for [{$this->config['host']}]");
        }

        $output = [];

        foreach ($commands as $commandParts) {
            $result = $this->executeSecureCommand($commandParts, $this->config['path']);
            $output[] = $result;

            if ($print) {
                echo $result;
            }
        }

        return implode("\n", $output);
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
     * Executes a raw, complex shell command string on the remote server.
     * Use this for trusted commands that require shell features like variables or redirection.
     *
     * @param string $command The raw command string to execute.
     * @return string The command's standard output.
     * @throws \RuntimeException on command failure.
     */
    public function runRawCommand(string $command): string
    {
        $this->connect();

        $fullCommand = "cd " . escapeshellarg($this->config['path']) . " && {$command}";
        $output = $this->ssh->exec($fullCommand);

        if ($this->ssh->getExitStatus() !== 0) {
            throw new \RuntimeException(
                "Remote command failed on [{$this->server}]:\n" .
                "Command: {$command}\n" .
                "Error: " . $this->ssh->getStdError()
            );
        }

        return $output;
    }

    /**
     * Executes a single, secure command on the remote server.
     *
     * @param array $commandParts The command and its arguments.
     * @param string $cwd The working directory to run the command in.
     * @return string The command's standard output.
     * @throws \RuntimeException on command failure.
     */
    protected function executeSecureCommand(array $commandParts, string $cwd): string
    {
        $this->connect();

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
}
