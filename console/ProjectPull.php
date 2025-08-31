<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;
use NumenCode\SyncOps\Traits\RunsLocalCommands;

class ProjectPull extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:project-pull
        {server       : The name of the remote server}
        {--m|no-merge : Do not merge changes into the local branch automatically}
        {--p|pull     : Execute "git pull" on the remote before pushing changes}
        {--message=   : Commit message for server changes (default: "Server changes")}';

    protected $description = 'Commits untracked changes on the remote server, pushes them to the origin,
                              and optionally merges them into the local branch.';

    public function handle(RemoteExecutor $executor)
    {
        $executor->connect($this->argument('server'));

        if ($executor->remoteIsClean()) {
            $this->info(PHP_EOL . '✔ No changes on the remote server.');
            return Command::SUCCESS;
        }

        $commitMessage = $this->option('message') ?: 'Server changes';

        $this->line("Committing the changes with message: '{$commitMessage}'");
        $executor->runAndPrint([
            'git add --all',
            sprintf('git commit -m "%s"', addslashes($commitMessage)),
        ]);

        if ($this->option('pull')) {
            $this->line('Pulling new changes on the remote:');
            $executor->runAndPrint(['git pull']);
        }

        $currentRemoteBranch = trim($executor->runAndGet('git rev-parse --abbrev-ref HEAD'));

        $this->line("Pushing the changes to origin ({$currentRemoteBranch}):");
        $executor->runAndPrint([
            'git push origin ' . $currentRemoteBranch,
        ]);

        if (!$this->option('no-merge')) {
            $this->line('Merging the changes locally:');
            $this->info($this->runLocalCommand(['git fetch']));
            $this->info($this->runLocalCommand(['git merge origin/' . $executor->config['branch_prod']]));
        }

        $this->info(PHP_EOL . '✔ Changes were successfully pulled into the local project.');
        return Command::SUCCESS;
    }
}
