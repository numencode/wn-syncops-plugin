<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class ProjectDeploy extends Command
{
    protected $signature = 'syncops:project-deploy
        {server       : The name of the remote server}
        {--f|fast     : Fast deploy (without clearing the cache)}
        {--c|composer : Force Composer install}
        {--m|migrate  : Run migrations}
        {--x|sudo     : Force super user (sudo)}';

    protected $description = 'Deploy project to a remote server via Git.';

    public function handle(): int
    {
        $this->newLine();

        try {
            $this->line("Connecting to remote server '{$this->argument('server')}'...");
            $this->newLine();
            $executor = $this->createExecutor($this->argument('server'));

            if (!$executor->ssh->remoteIsClean()) {
                $this->error("✘ Remote changes detected. Aborting deployment process.");
                $this->newLine();
                $this->warn("Please run a command:");
                $this->info("php artisan syncops:project-pull " . $this->argument('server'));
                return self::FAILURE;
            }

            $success = $this->option('fast')
                ? $this->fastDeploy($executor, (bool)$this->option('sudo'))
                : $this->deploy($executor, (bool)$this->option('sudo'));

            $this->handleOwnership($executor, (bool)$this->option('sudo'));

            if (!$success) {
                $this->error("✘ Project deployment FAILED. Check error logs to see what went wrong.");
                return self::FAILURE;
            }

            $this->info("✔ Project was successfully deployed.");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("✘ An error occurred on server '{$this->argument('server')}':");
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Full deploy (with maintenance mode + cache flushes).
     */
    protected function deploy(RemoteExecutor $executor, bool $useSudo): bool
    {
        $this->line('Putting the application into maintenance mode:');
        $executor->ssh->runAndPrint([$this->wrapSudo(['php', 'artisan', 'down'], $useSudo)]);

        sleep(1);

        $this->line('Flushing the application cache:');
        $executor->ssh->runAndPrint($this->clearCommands($useSudo));

        $success = $this->fastDeploy($executor, $useSudo);

        $this->line('Rebuilding the application cache:');
        $executor->ssh->runAndPrint($this->clearCommands($useSudo));

        $this->line('Bringing the application out of the maintenance mode:');
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
            $this->line('Handling file ownership...');
            $executor->ssh->runAndPrint([
                $this->wrapSudo(['chown', $config['permissions']['root_user'], '-R', '.'], $useSudo)
            ]);
        }

        $branchMain = array_key_exists('branch_main', $config) ? $config['branch_main'] : 'main';

        // If branch_main is explicitly false => pull-only mode (no merge)
        if ($branchMain === false) {
            return $this->pullDeploy($executor, $useSudo);
        }

        return $this->mergeDeploy($executor, $useSudo);
    }

    /**
     * Pull-based deploy.
     */
    protected function pullDeploy(RemoteExecutor $executor, bool $useSudo): bool
    {
        $this->line('Deploying the project (pull mode):');
        $this->newLine();

        $result = $executor->ssh->runAndPrint([['git', 'pull']]);

        if (str_contains($result, 'CONFLICT')) {
            $this->error("✘ Conflicts detected. Reverting changes...");
            $executor->ssh->runAndPrint([['git', 'reset', '--hard']]);
            return false;
        }

        $this->afterDeploy($executor, $result, $useSudo);
        return true;
    }

    /**
     * Merge-based deploy.
     */
    protected function mergeDeploy(RemoteExecutor $executor, bool $useSudo): bool
    {
        $this->line('Deploying the project (merge mode):');
        $this->newLine();

        $config = $executor->config;
        $branchMain = $config['branch_main'] ?? 'main';

        $result = $executor->ssh->runAndPrint([
            ['git', 'fetch'],
            ['git', 'merge', 'origin/' . $branchMain],
        ]);

        if (str_contains($result, 'CONFLICT')) {
            $this->error("✘ Conflicts detected. Reverting...");
            $executor->ssh->runAndPrint([['git', 'reset', '--hard']]);
            return false;
        }

        // Push target branch if configured
        $pushBranch = $config['branch'] ?? $config['branch_prod'] ?? null;
        if (!empty($pushBranch)) {
            $executor->ssh->runAndPrint([['git', 'push', 'origin', $pushBranch]]);
            $this->newLine();
        }

        $this->afterDeploy($executor, $result, $useSudo);
        return true;
    }

    /**
     * Actions after a successful deployment (composer, migrations).
     */
    protected function afterDeploy(RemoteExecutor $executor, string $result, bool $useSudo): void
    {
        if ($this->option('composer') || str_contains($result, 'composer.lock')) {
            $this->newLine();
            $this->line('Running Composer install (remote)...');
            $this->newLine();
            $executor->ssh->runAndPrint($this->composerCommands($useSudo));
        }

        if ($this->option('migrate') || $this->option('composer') || str_contains($result, 'composer.lock')) {
            $this->newLine();
            $this->line('Running migrations (remote)...');
            $this->newLine();
            $executor->ssh->runAndPrint($this->migrateCommands($useSudo));
        }

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

        $this->line('Handling file ownership...');
        $this->newLine();

        foreach ($folders as $folder) {
            $executor->ssh->runAndPrint([
                $this->wrapSudo(['chown', $config['permissions']['web_user'], '-R', $folder], $useSudo)
            ]);
        }
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

    protected function clearCommands(bool $useSudo): array
    {
        return [
            $this->wrapSudo(['php', 'artisan', 'route:clear'], $useSudo),
            $this->wrapSudo(['php', 'artisan', 'config:clear'], $useSudo),
            $this->wrapSudo(['php', 'artisan', 'cache:clear'], $useSudo),
        ];
    }

    protected function migrateCommands(bool $useSudo): array
    {
        return [
            $this->wrapSudo(['php', 'artisan', 'winter:up'], $useSudo),
        ];
    }

    protected function composerCommands(bool $useSudo): array
    {
        return [
            $this->wrapSudo(['composer', 'install', '--no-dev'], $useSudo),
        ];
    }

    protected function createExecutor(string $server): RemoteExecutor
    {
        return new RemoteExecutor($server);
    }
}
