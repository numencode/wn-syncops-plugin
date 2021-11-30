<?php namespace NumenCode\Backup\Console;

use Illuminate\Console\Command;

class ProjectCommitCommand extends Command
{
    protected $signature = 'project:commit {message="Server changes"}';

    protected $description = 'Add & commit project changes and push them to the remote git repository.';

    public function handle()
    {
        $message = $this->argument('message');
        $result = shell_exec('git status');

        if (str_contains($result, 'nothing to commit')) {
            $this->line('');
            $this->alert('No changes on a remote server.');

            return;
        }

        $this->question('Committing the changes:');
        $this->info(shell_exec('git add --all'));
        $this->info(shell_exec('git commit -m "' . $message . '"'));

        $this->question('Pushing the changes:');
        $this->info(shell_exec('git push'));

        $this->alert('Project changes were successfully pushed to the git repository.');
    }
}
