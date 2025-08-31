<?php namespace NumenCode\SyncOps\Traits;

use Symfony\Component\Process\Process;

trait RunsLocalCommands
{
    protected function runLocalCommand(array $command): string
    {
        $process = Process::fromShellCommandline(implode(' ', $command));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Command failed: {$process->getErrorOutput()}");
        }

        return $process->getOutput();
    }
}
