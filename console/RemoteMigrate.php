<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use NumenCode\SyncOps\Classes\SyncOpsCommand;

/**
 * Runs Winter's migrations ("winter:up") on a server. A separate command, because Winter loads no plugins
 * when "winter:up" is on the command line, so syncops:remote-artisan can't pass it on.
 */
class RemoteMigrate extends SyncOpsCommand
{
    protected $signature = 'syncops:remote-migrate
        {server : The connection name from config/syncops.php}';

    protected $description = 'Run the Winter CMS migrations (winter:up) on a server.';

    protected function process(): int
    {
        $name = (string) $this->argument('server');
        $server = $this->server($name);
        $command = [...$server->php(), 'artisan', 'winter:up', '--no-interaction'];

        $this->comment('$ ' . implode(' ', $command));
        $this->newLine();

        $result = $server->run($command, $this->liveOutput());
        $this->newLine();

        if (!$result->successful()) {
            $this->error("✘ The migrations failed on '{$name}' (exit code {$result->exitCode}).");

            return self::FAILURE;
        }

        $this->info('✔ Migrations are up to date.');

        return self::SUCCESS;
    }
}
