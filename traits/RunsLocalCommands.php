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
     * @return string The output from the command.
     * @throws \RuntimeException on command failure.
     */
    protected function runLocalCommand(string $command): string
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }
}
