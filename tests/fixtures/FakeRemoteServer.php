<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Tests\Fixtures;

use NumenCode\SyncOps\Classes\CommandResult;
use NumenCode\SyncOps\Classes\RemoteServer;
use phpseclib3\Net\SFTP;

/**
 * A RemoteServer that never connects: it records every command and answers with scripted results.
 *
 * Results are matched by regular expression against the shell command (run() commands are escaped, so
 * match e.g. "/'git' 'merge'/"); the first matching pattern wins, everything else succeeds with no output.
 */
class FakeRemoteServer extends RemoteServer
{
    /**
     * @var array<int, string> all executed shell commands, in order
     */
    public array $commands = [];

    /**
     * @var array<string, string> remote path => contents written with putContents()
     */
    public array $written = [];

    /**
     * @var array<int, string> removed remote paths
     */
    public array $removed = [];

    /**
     * @var array<string, CommandResult|callable> pattern => result (or callable returning one)
     */
    public array $results = [];

    /**
     * @var array<string, string> remote path => contents returned by download()
     */
    public array $files = [];

    /**
     * @var array<string, int> result of listFiles()
     */
    public array $listing = [];

    public function connect(): SFTP
    {
        throw new \LogicException('The fake server never connects.');
    }

    public function disconnect(): void
    {
    }

    public function fingerprint(): string
    {
        return 'SHA256:fake';
    }

    public function runShell(string $script, ?callable $onOutput = null, bool $inProject = true): CommandResult
    {
        $this->commands[] = $script;

        foreach ($this->results as $pattern => $result) {
            if (preg_match($pattern, $script)) {
                $result = is_callable($result) ? $result($script) : $result;

                if ($onOutput !== null && $result->output !== '') {
                    $onOutput($result->output);
                }

                return $result;
            }
        }

        return new CommandResult(0, '');
    }

    public function projectPath(string $relative = ''): string
    {
        return '/srv/project' . ($relative !== '' ? '/' . $relative : '');
    }

    public function makeTempDirectory(): string
    {
        return '/tmp/syncops.test';
    }

    public function remove(string $path): void
    {
        $this->removed[] = $path;
    }

    public function putContents(string $remoteFile, string $contents): void
    {
        $this->written[$remoteFile] = $contents;
    }

    public function download(string $remoteFile, string $localFile): void
    {
        file_put_contents($localFile, $this->files[$remoteFile] ?? '');
    }

    public function upload(string $localFile, string $remoteFile): void
    {
        $this->written[$remoteFile] = (string) file_get_contents($localFile);
    }

    public function size(string $remoteFile): ?int
    {
        return isset($this->files[$remoteFile]) ? strlen($this->files[$remoteFile]) : null;
    }

    public function listFiles(string $directory): array
    {
        return $this->listing;
    }

    /**
     * Whether a command matching the pattern was executed.
     */
    public function ran(string $pattern): bool
    {
        return $this->position($pattern) !== null;
    }

    /**
     * Index of the first command matching the pattern, or null.
     */
    public function position(string $pattern): ?int
    {
        foreach ($this->commands as $index => $command) {
            if (preg_match($pattern, $command)) {
                return $index;
            }
        }

        return null;
    }
}
