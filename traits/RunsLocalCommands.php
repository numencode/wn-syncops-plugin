<?php namespace NumenCode\SyncOps\Traits;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

trait RunsLocalCommands
{
    /**
     * Runs a local shell command.
     *
     * @param string $command The shell command to run.
     * @param int $timeout Timeout in seconds (default: 60).
     * @return string The standard output from the command.
     *
     * @throws ProcessFailedException   If the command exits with a non-zero status.
     * @throws ProcessTimedOutException If the command exceeds the given timeout.
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
