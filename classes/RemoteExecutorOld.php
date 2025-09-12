<?php namespace NumenCode\SyncOps\Classes;

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class RemoteExecutorOld
{
    protected ?SSH2 $ssh = null;
    protected ?SFTP $sftp = null;
    protected string $server;
    protected mixed $credentials;
    public array $config;

//    protected function loadConfig(string $server): void
//    {
//        $this->server = $server;
//        $this->config = config("syncops.connections.$server");
//
//        if (!$this->config) {
//            throw new \RuntimeException("Connection configuration not found for server: $server");
//        }
//
//        $this->credentials = isset($this->config['password']) && empty($this->config['key_path'])
//            ? $this->config['password']
//            : PublicKeyLoader::load(file_get_contents($this->config['key_path']));
//    }

//    public function connectSsh(string $server): SSH2
//    {
//        if (!$this->ssh) {
//            $this->loadConfig($server);
//
//            $this->ssh = new SSH2($this->config['host'], $this->config['port'] ?? 22);
//
//            if (!$this->ssh->login($this->config['username'], $this->credentials)) {
//                throw new \RuntimeException("SSH login failed for server: {$server}");
//            }
//        }
//
//        return $this->ssh;
//    }

//    public function connectSftp(string $server): SFTP
//    {
//        if (!$this->sftp) {
//            $this->loadConfig($server);
//
//            $this->sftp = new SFTP($this->config['host'], $this->config['port'] ?? 22);
//
//            if (!$this->sftp->login($this->config['username'], $this->credentials)) {
//                throw new \RuntimeException("SFTP login failed for server: {$server}");
//            }
//        }
//
//        return $this->sftp;
//    }

//    public function connectBoth(string $server): bool
//    {
//        $this->connectSsh($server);
//        $this->connectSftp($server);
//        return true;
//    }

    // -----------------
    // Convenience API
    // -----------------

//    public function exec(string $server, string $command): string
//    {
//        $ssh = $this->connectSsh($server);
//        return $ssh->exec($command);
//    }
//
//    public function download(string $server, string $remoteFile, string $localFile): void
//    {
//        $sftp = $this->connectSftp($server);
//
//        if (!$sftp->get($remoteFile, $localFile)) {
//            throw new \RuntimeException("Failed to download file: $remoteFile");
//        }
//    }
//
//    public function upload(string $server, string $localFile, string $remoteFile): void
//    {
//        $sftp = $this->connectSftp($server);
//
//        if (!$sftp->put($remoteFile, file_get_contents($localFile))) {
//            throw new \RuntimeException("Failed to upload file: $localFile -> $remoteFile");
//        }
//    }

    /**
     * Securely executes multiple commands on the remote server.
     * Each command must be an array of arguments.
     *
     * @param array $commands An array of commands, e.g., [['git', 'status'], ['ls', '-la']]
     * @param bool $print Whether to echo the output in real-time.
     * @return string The combined output of all commands.
     */
//    public function runCommands(array $commands, bool $print = false): string
//    {
//        if (!isset($this->config['path'])) {
//            throw new \RuntimeException("Path is not defined for [$this->server]");
//        }
//
//        $output = [];
//        $basePath = $this->config['path'];
//
//        foreach ($commands as $commandParts) {
//            $result = $this->executeSecureCommand($commandParts, $basePath);
//            $output[] = $result;
//
//            if ($print) {
//                echo $result;
//            }
//        }
//
//        return implode("\n", $output);
//    }

    /**
     * A secure wrapper for runCommands that prints output.
     *
     * @param array $commands An array of commands, e.g., [['git', 'add', '--all']]
     * @return string The combined, trimmed output.
     */
//    public function runAndPrint(array $commands): string
//    {
//        return trim($this->runCommands($commands, true));
//    }

    /**
     * A secure wrapper to run a single command and get its output.
     *
     * @param array $command A single command as an array, e.g., ['git', 'status']
     * @return string The trimmed output.
     */
//    public function runAndGet(array $command): string
//    {
//        return trim($this->runCommands([$command], false));
//    }

//    public function remoteIsClean(): bool
//    {
//        $result = $this->runAndGet(['git', 'status', '--porcelain']);
//        return $result === '';
//    }

    /**
     * Executes a single, secure command on the remote server.
     *
     * @param array $commandParts The command and its arguments.
     * @param string $cwd The working directory to run the command in.
     * @return string The command's standard output.
     * @throws \RuntimeException on command failure.
     */
//    private function executeSecureCommand(array $commandParts, string $cwd): string
//    {
//        $escapedParts = array_map('escapeshellarg', $commandParts);
//        $safeCommand = implode(' ', $escapedParts);
//        $fullCommand = "cd " . escapeshellarg(str_replace('~', '$HOME', $cwd)) . " && {$safeCommand}";
//
//        $output = $this->ssh->exec($fullCommand);
//
//        if ($this->ssh->getExitStatus() !== 0) {
//            throw new \RuntimeException(
//                "Remote command failed on [{$this->server}]:\n" .
//                "Command: {$safeCommand}\n" .
//                "Error: " . $this->ssh->getStdError()
//            );
//        }
//
//        return $output;
//    }

    /**
     * Executes a raw, complex shell command string on the remote server.
     * Use this for trusted commands that require shell features like variables or redirection.
     *
     * @param string $command The raw command string to execute.
     * @return string The command's standard output.
     * @throws \RuntimeException on command failure.
     */
//    public function runRawCommand(string $command): string
//    {
//        if (!isset($this->config['path'])) {
//            throw new \RuntimeException("Path is not defined for [$this->server]");
//        }
//
//        $fullCommand = "cd " . escapeshellarg($this->config['path']) . " && {$command}";
//
//        $output = $this->ssh->exec($fullCommand);
//
//        if ($this->ssh->getExitStatus() !== 0) {
//            throw new \RuntimeException(
//                "Remote command failed on [{$this->server}]:\n" .
//                "Command: {$command}\n" .
//                "Error: " . $this->ssh->getStdError()
//            );
//        }
//
//        return $output;
//    }
}
