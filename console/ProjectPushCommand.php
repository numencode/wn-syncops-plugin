<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Traits\RunsLocalCommands;

class ProjectPushCommand extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:project-push
        {--m|message=Server changes : Commit message for local changes (default: "Server changes")}';

    protected $description = 'Adds and commits project changes locally and pushes them to the remote repository.';

    public function handle()
    {
        $status = $this->runLocalCommand(['git status']);

        if (str_contains($status, 'nothing to commit')) {
            $this->info(PHP_EOL . '✔ No changes to commit.');
            return Command::SUCCESS;
        }

        $commitMessage = $this->option('message') ?: 'Server changes';

        $this->line("Committing the changes with message: '{$commitMessage}'");
        $this->runLocalCommand(['git add --all']);
        $this->runLocalCommand([sprintf('git commit -m "%s"', addslashes($commitMessage))]);

        $this->line('Pushing the changes to origin:');
        $this->runLocalCommand(['git push']);

        $this->info(PHP_EOL . '✔ Project changes were successfully pushed to the git repository.');
        return Command::SUCCESS;
    }
}
