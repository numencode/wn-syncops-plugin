<?php

namespace NumenCode\SyncOps\Traits;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

trait RunsLocalCommands
{
    /**
     * Runs a local shell command.
     *
     * @param string $command The shell command to run.
     * @param int $timeout
     * @return string The output from the command.
     * @throws \RuntimeException on command failure.
     */
    protected function runLocalCommand(string $command, int $timeout = 60): string
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }
}
