<?php namespace NumenCode\SyncOps\Traits;

use NumenCode\SyncOps\Classes\SshExecutor;
use NumenCode\SyncOps\Classes\RemoteExecutor;

trait RunsRemoteCommands
{
    protected ?SshExecutor $sshExecutor = null;

    protected function ssh(string $server): SshExecutor
    {
        if (!$this->sshExecutor) {
            $executor = new RemoteExecutor($server);
            $this->sshExecutor = $executor->ssh;
        }

        return $this->sshExecutor;
    }

    protected function runRemote(string $server, array $command): string
    {
        return $this->ssh($server)->runAndGet($command);
    }

    protected function runRemoteAndPrint(string $server, array $commands): string
    {
        return $this->ssh($server)->runAndPrint($commands);
    }

    protected function runRemoteRaw(string $server, string $command): string
    {
        return $this->ssh($server)->runRawCommand($command);
    }
}
