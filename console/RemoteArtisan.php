<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use NumenCode\SyncOps\Classes\SyncOpsCommand;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * Runs an artisan command on a server. Everything after the server name is passed on unchanged, including
 * options (e.g. `syncops:remote-artisan production cache:clear --force`).
 *
 * Winter loads no plugins when "migrate", "winter:up", "winter:update", "winter:env", "winter:version" or
 * "winter:manifest" appears on the command line, so these can't be run this way; use syncops:remote-migrate
 * and syncops:remote-health instead.
 */
class RemoteArtisan extends SyncOpsCommand
{
    protected $signature = 'syncops:remote-artisan
        {server          : The connection name from config/syncops.php}
        {artisanCommand* : The artisan command with its arguments and options}';

    protected $description = 'Run an artisan command on a server (all arguments and options are passed on).';

    public function __construct()
    {
        parent::__construct();

        // Options of the remote command (e.g. --force) are not options of this command
        $this->ignoreValidationErrors();
    }

    protected function process(): int
    {
        $name = (string) $this->argument('server');
        $arguments = $this->remoteArguments($name);

        if ($arguments === []) {
            $this->error('✘ Give the artisan command to run, e.g. `syncops:remote-artisan production cache:clear`.');

            return self::FAILURE;
        }

        if (!in_array('--no-interaction', $arguments, true) && !in_array('-n', $arguments, true)) {
            $arguments[] = '--no-interaction';
        }

        $server = $this->server($name);
        $command = [...$server->php(), 'artisan', ...$arguments];

        $this->comment('$ ' . implode(' ', $command));
        $this->newLine();

        $result = $server->run($command, $this->liveOutput());
        $this->newLine();

        if (!$result->successful()) {
            $this->error("✘ The command failed on '{$name}' (exit code {$result->exitCode}).");

            return $result->exitCode;
        }

        $this->info('✔ Done.');

        return self::SUCCESS;
    }

    /**
     * The command line tokens after the server name, exactly as given.
     *
     * @return array<int, string>
     */
    protected function remoteArguments(string $server): array
    {
        if (!$this->input instanceof ArgvInput) {
            return array_values((array) $this->argument('artisanCommand'));
        }

        $tokens = (new ReflectionProperty(ArgvInput::class, 'tokens'))->getValue($this->input);
        $position = array_search($server, $tokens, true);
        $arguments = $position === false ? [] : array_slice($tokens, $position + 1);

        if (($arguments[0] ?? null) === '--') {
            array_shift($arguments);
        }

        return array_values(array_map('strval', $arguments));
    }
}
