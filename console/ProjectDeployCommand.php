<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class ProjectDeploy extends Command
{
    protected $signature = 'syncops:project-deploy
        {server : The name of the remote server}
        {--f|fast : Fast deploy (without clearing the cache)}
        {--c|composer : Force Composer install}
        {--m|migrate : Run migrations}
        {--x|sudo : Force super user (sudo)}';

    protected $description = 'Deploy project to a remote server via Git.';

    /**
     * Entry point.
     */
    public function handle(): int
    {
        $server = $this->argument('server');

        try {
            $this->comment("Connecting to remote server '{$server}'...");
            $executor = new RemoteExecutor($server);
            $config = $executor->config;

            // If remote SSH login fails early, SshExecutor will throw when methods are used.
            // Check repository cleanliness first (prevent deploying on dirty working trees)
            if (!$executor->ssh->remoteIsClean()) {
                $this->error("Remote repository is not clean. Commit or stash changes before deploying.");
                return self::FAILURE;
            }

            $useSudo = $this->option('sudo') ? true : false;

            $this->line('');

            $success = $this->option('fast')
                ? $this->fastDeploy($executor, $useSudo)
                : $this->deploy($executor, $useSudo);

            $this->handleOwnership($executor, $useSudo);

            if (!$success) {
                $this->error("Project deployment FAILED. Check error logs to see what went wrong." . PHP_EOL);
                return self::FAILURE;
            }

            $this->alert("Project was successfully deployed.");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("✘ An error occurred on server '{$server}':");
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Full deploy (with maintenance mode + cache flushes)
     */
    protected function deploy(RemoteExecutor $executor, bool $useSudo): bool
    {
        $server = $this->argument('server');

        $this->question('Putting the application into maintenance mode:');
        $executor->ssh->runAndPrint([$this->wrapSudo(['php', 'artisan', 'down'], $useSudo)]);

        sleep(1);

        $this->question('Flushing the application cache:');
        $executor->ssh->runAndPrint($this->clearCommands($useSudo));

        $success = $this->fastDeploy($executor, $useSudo);

        $this->question('Rebuilding the application cache:');
        $executor->ssh->runAndPrint($this->clearCommands($useSudo));

        $this->question('Bringing the application out of the maintenance mode:');
        $executor->ssh->runAndPrint([$this->wrapSudo(['php', 'artisan', 'up'], $useSudo)]);

        return $success;
    }

    /**
     * Fast deploy: optionally adjust ownership then do pull/merge.
     */
    protected function fastDeploy(RemoteExecutor $executor, bool $useSudo): bool
    {
        $config = $executor->config;

        if (!empty($config['permissions']['root_user'])) {
            $this->question('Handling file ownership.');
            $executor->ssh->runAndPrint([
                $this->wrapSudo(['chown', $config['permissions']['root_user'], '-R', '.'], $useSudo)
            ]);
            $this->line('');
        }

        $branchMain = array_key_exists('branch_main', $config) ? $config['branch_main'] : 'main';

        // If branch_main is explicitly false => pull-only mode (no merge)
        if ($branchMain === false) {
            return $this->pullDeploy($executor, $useSudo);
        }

        return $this->mergeDeploy($executor, $useSudo);
    }

    /**
     * Pull-based deploy
     */
    protected function pullDeploy(RemoteExecutor $executor, bool $useSudo): bool
    {
        $this->question('Deploying the project (pull mode):');

        $result = $executor->ssh->runAndPrint([['git', 'pull']]);

        if (str_contains($result, 'CONFLICT')) {
            $this->error("Conflicts detected. Reverting changes...");
            $executor->ssh->runAndPrint([['git', 'reset', '--hard']]);
            return false;
        }

        $this->afterDeploy($executor, $result, $useSudo);
        return true;
    }

    /**
     * Merge-based deploy
     */
    protected function mergeDeploy(RemoteExecutor $executor, bool $useSudo): bool
    {
        $this->question('Deploying the project (merge mode):');

        $config = $executor->config;
        $branchMain = $config['branch_main'] ?? 'main';

        $result = $executor->ssh->runAndPrint([
            ['git', 'fetch'],
            ['git', 'merge', 'origin/' . $branchMain],
        ]);

        if (str_contains($result, 'CONFLICT')) {
            $this->error("Conflicts detected. Reverting...");
            $executor->ssh->runAndPrint([['git', 'reset', '--hard']]);
            return false;
        }

        // push target branch if configured (backwards-compatible with previous code)
        $pushBranch = $config['branch'] ?? $config['branch_prod'] ?? null;
        if (!empty($pushBranch)) {
            $executor->ssh->runAndPrint([['git', 'push', 'origin', $pushBranch]]);
        }

        $this->afterDeploy($executor, $result, $useSudo);
        return true;
    }

    /**
     * Actions after a successful deploy (composer, migrations).
     */
    protected function afterDeploy(RemoteExecutor $executor, string $result, bool $useSudo): void
    {
        // Composer
        if ($this->option('composer') || str_contains($result, 'composer.lock')) {
            $this->question('Running Composer install (remote)...');
            $executor->ssh->runAndPrint($this->composerCommands($useSudo));
        }

        // Migrations
        if ($this->option('migrate')) {
            $this->question('Running migrations (remote)...');
            $executor->ssh->runAndPrint($this->migrateCommands($useSudo));
        }

        // ensure correct ownership after deploy
        $this->handleOwnership($executor, $useSudo);
    }

    /**
     * Handle file ownership for web user folders configured in syncops config.
     */
    protected function handleOwnership(RemoteExecutor $executor, bool $useSudo): void
    {
        $config = $executor->config;

        if (empty($config['permissions']['web_user']) || empty($config['permissions']['web_folders'])) {
            return;
        }

        $folders = array_map('trim', explode(',', $config['permissions']['web_folders']));

        $this->question('Handling file ownership.');
        $this->line('');

        foreach ($folders as $folder) {
            $executor->ssh->runAndPrint([
                $this->wrapSudo(['chown', $config['permissions']['web_user'], '-R', $folder], $useSudo)
            ]);
        }
    }

    /**
     * Clear / cache related commands as arrays of args (suitable for runAndPrint).
     */
    protected function clearCommands(bool $useSudo): array
    {
        return [
            $this->wrapSudo(['php', 'artisan', 'route:clear'], $useSudo),
            $this->wrapSudo(['php', 'artisan', 'config:clear'], $useSudo),
            $this->wrapSudo(['php', 'artisan', 'cache:clear'], $useSudo),
        ];
    }

    /**
     * Migration commands (array-of-arrays).
     */
    protected function migrateCommands(bool $useSudo): array
    {
        return [
            $this->wrapSudo(['php', 'artisan', 'winter:up'], $useSudo),
        ];
    }

    /**
     * Composer commands (array-of-arrays).
     */
    protected function composerCommands(bool $useSudo): array
    {
        // prefer running composer as normal user unless sudo requested explicitly
        return [
            $this->wrapSudo(['composer', 'install', '--no-dev'], $useSudo),
        ];
    }

    /**
     * Helper: prepend 'sudo' as first arg when requested.
     */
    protected function wrapSudo(array $commandParts, bool $useSudo): array
    {
        if ($useSudo) {
            array_unshift($commandParts, 'sudo');
        }
        return $commandParts;
    }
}
