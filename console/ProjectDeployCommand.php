<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class ProjectDeployCommand extends Command
{
    protected $signature = 'syncops:project-deploy {server : The name of the remote server}';

    protected $description = 'Deploys the project on the remote server';

    public function handle(RemoteExecutor $executor)
    {
        try {
            $executor->connect($this->argument('server'));

            $executor->abortIfUncommittedChanges();

            $executor->runAndPrint([
                'php artisan down',
                'git pull origin prod',
//                'composer install --no-dev',
//                'php artisan winter:up',
                'php artisan up',
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}
