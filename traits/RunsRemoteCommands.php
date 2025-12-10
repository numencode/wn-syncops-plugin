<?php namespace NumenCode\SyncOps\Traits;

use NumenCode\SyncOps\Classes\SshExecutor;
use NumenCode\SyncOps\Classes\RemoteExecutor;

/**
 * Small helper trait for running commands on a remote server via SSH.
 *
 * This trait lazily creates and caches a single SshExecutor instance
 * for the lifetime of the class using it. The first server name passed
 * to ssh()/runRemote()/runRemoteAndPrint()/runRemoteRaw "wins"; subsequent
 * calls with a different $server will still reuse the same executor
 * unless you explicitly overwrite $this->sshExecutor.
 */
trait RunsRemoteCommands
{
    /**
     * Cached SSH executor instance for the current command run.
     */
    protected ?SshExecutor $sshExecutor = null;

    /**
     * Get (or create) a cached SshExecutor for the given server.
     *
     * Note: The first server you call this with is the one that will be
     * used for all subsequent calls, unless you manually overwrite the
     * $sshExecutor property.
     */
    protected function ssh(string $server): SshExecutor
    {
        if ($this->sshExecutor === null) {
            $executor = new RemoteExecutor($server);
            $this->sshExecutor = $executor->ssh;
        }

        return $this->sshExecutor;
    }

    /**
     * Run a single, secure command on the remote server and return its trimmed output.
     *
     * @param string $server  The configured server key from syncops.php
     * @param array  $command A single command as an array, e.g. ['git', 'status']
     */
    protected function runRemote(string $server, array $command): string
    {
        return $this->ssh($server)->runAndGet($command);
    }

    /**
     * Run one or more commands on the remote server and echo their output.
     *
     * @param string $server   The configured server key from syncops.php
     * @param array  $commands An array of commands, e.g. [['git', 'status'], ['ls', '-la']]
     */
    protected function runRemoteAndPrint(string $server, array $commands): string
    {
        return $this->ssh($server)->runAndPrint($commands);
    }

    /**
     * Run a raw shell command string on the remote server in the project path.
     *
     * @param string $server  The configured server key from syncops.php
     * @param string $command Raw shell string, e.g. 'php artisan migrate --force'
     */
    protected function runRemoteRaw(string $server, string $command): string
    {
        return $this->ssh($server)->runRawCommand($command);
    }
}
