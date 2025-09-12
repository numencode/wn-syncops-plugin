<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutorOld;

class ProjectDeployCommand extends Command
{
    protected $signature = "syncops:project-deploy
        {server           : The name of the remote server}
        {--f|--fast       : Fast deploy (without clearing the cache)}
        {--c|--composer   : Force Composer install}
        {--m|--no-migrate : Don't run migrations}
        {--s|--sudo       : Force super user (sudo)}";

    protected $description = 'Deploys the project on the remote server';

    protected $sudo;
    protected RemoteExecutorOld $executor;

    public function handle(RemoteExecutorOld $executor)
    {
        $this->executor = $executor;

        try {
            $this->executor->connect($this->argument('server'));

            $this->executor->abortIfRemoteHasChanges();

            if ($this->option('sudo')) {
                $this->sudo = 'sudo ';
            }

            $success = $this->option('fast') ? $this->fastDeploy() : $this->deploy();

            $this->handleOwnership();

            if ($success) {
                $this->info("✔ Project was successfully deployed.");
            } else {
                $this->error("⚠ Project deployment FAILED. Check error logs to see what went wrong.");
            }
        } catch (\Throwable $e) {
            $this->executor->runAndPrint(['php artisan up']);

            $this->error($e->getMessage());
        }
    }

    protected function fastDeploy()
    {
        if (!empty($this->executor->config['permissions']['root_user'])) {
            $this->line("Handling file ownership.");
            $this->executor->runAndPrint([$this->sudo . 'chown ' . $this->executor->config['permissions']['root_user'] . ' -R .']);
            $this->line('');
        }

        if (array_get($this->executor->config, 'branch_main', 'main') === false) {
            return $this->pullDeploy();
        } else {
            return $this->mergeDeploy();
        }
    }

    protected function deploy()
    {
        $this->line("Putting the application into maintenance mode:");
        $this->executor->runAndPrint([$this->sudo . 'php artisan down']);
        sleep(1);

        $this->line("Flushing the application cache:");
        $this->executor->runAndPrint($this->clearCommands());

        $success = $this->fastDeploy();

        $this->line("Rebuilding the application cache:");
        $this->executor->runAndPrint($this->clearCommands());

        $this->line("Bringing the application out of the maintenance mode:");
        $this->executor->runAndPrint([$this->sudo . 'php artisan up']);

        return $success;
    }

    public function pullDeploy()
    {
        $this->line("Deploying the project (pull mode):");

        $result = $this->executor->runAndPrint(['git pull']);

        if (str_contains($result, 'CONFLICT')) {
            $this->error("⚠ Conflicts detected. Reverting changes...");
            $this->executor->runAndPrint(['git reset --hard']);

            return false;
        }

        $this->afterDeploy($result);

        return true;
    }

    public function mergeDeploy()
    {
        $this->line("Deploying the project (merge mode):");

        $result = $this->executor->runAndPrint([
            'git fetch',
            'git merge origin/' . array_get($this->executor->config, 'branch_main', 'main'),
        ]);

        if (str_contains($result, 'CONFLICT')) {
            $this->error("⚠ Conflicts detected. Reverting changes...");
            $this->executor->runAndPrint(['git reset --hard']);

            return false;
        }

        $this->executor->runAndPrint(['git push origin ' . $this->executor->config['branch_prod']]);

        $this->afterDeploy($result);

        return true;
    }

    public function afterDeploy($result)
    {
        if ($this->option('composer') || str_contains($result, 'composer.lock')) {
            $this->executor->runAndPrint($this->composerCommands());
        }

        if (!$this->option('no-migrate')) {
            $this->executor->runAndPrint($this->migrateCommands());
        }

        $this->handleOwnership();
    }

    protected function handleOwnership()
    {
        if (empty($this->executor->config['permissions']['web_user']) ||
            empty($this->executor->config['permissions']['web_folders']))
        {
            return;
        }

        $folders = explode(',', $this->executor->config['permissions']['web_folders']);

        $this->line("Handling file ownership.");

        foreach ($folders as $folder) {
            $this->executor->runAndPrint([$this->sudo . 'chown ' . $this->executor->config['permissions']['web_user'] . ' ' . $folder . ' -R']);
        }
    }

    protected function clearCommands()
    {
        return [
            $this->sudo . 'php artisan cache:clear',
            $this->sudo . 'php artisan config:clear',
            $this->sudo . 'php artisan view:clear',
            $this->sudo . 'php artisan route:clear',
            $this->sudo . 'php artisan clear-compiled',
        ];
    }

    protected function migrateCommands()
    {
        return [
            $this->sudo . 'php artisan winter:up',
        ];
    }

    protected function composerCommands()
    {
        return [
            'composer install --no-dev',
        ];
    }
}
