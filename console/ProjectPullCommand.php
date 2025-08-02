<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class ProjectPullCommand extends Command
{
    protected $signature = 'syncops:project-pull
        {server         : The name of the remote server}
        {--p|--pull     : Execute "git pull" command before "git push"}
        {--m|--no-merge : Do not merge changes automatically}';

    protected $description = 'Fetch changes from production environment and merge them into the local project.';

    public function handle(RemoteExecutor $executor)
    {
        $executor->connect($this->argument('server'));

        if ($executor->hasNoUncommittedChanges()) {
            $this->alert('No changes on a remote server.');

            return false;
        }

        $this->line('Committing the changes:');
        $executor->runAndPrint([
            'git add --all',
            'git commit -m "Server changes"',
        ]);

        if ($this->option('pull')) {
            $this->line('Pulling new changes:');
            $executor->runAndPrint([
                'git pull',
            ]);
        }

        $this->line('Pushing the changes:');
        $executor->runAndPrint([
            'git push origin ' . $executor->config['branch'],
        ]);

        if (!$this->option('no-merge')) {
            $this->line('Merging the changes:');
            $this->info(shell_exec('git fetch'));
            $this->info(shell_exec('git merge origin/' . $executor->config['branch']));
        }

        $this->alert('Changes were successfully pulled into the project.');
    }
}
