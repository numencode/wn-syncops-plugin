<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Classes;

use RuntimeException;

/**
 * The result of a remote command: exit code, standard output and standard error.
 */
final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $error = '',
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Throws when the command failed. The message names the step ($label), never the command itself,
     * so secrets in the command line can't end up in the output.
     */
    public function throwIfFailed(string $label): self
    {
        if (!$this->successful()) {
            $details = trim($this->error) !== '' ? trim($this->error) : trim($this->output);

            throw new RuntimeException(
                "{$label} failed (exit code {$this->exitCode})" . ($details !== '' ? ":\n{$details}" : '.')
            );
        }

        return $this;
    }
}
