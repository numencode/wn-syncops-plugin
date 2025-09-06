<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Traits\RunsLocalCommands;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ProjectPush extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:project-push
        {--m|message=Server changes : Commit message for local changes (default: "Server changes")}';

    protected $description = 'Adds and commits project changes locally and pushes them to the remote repository.';

    public function handle()
    {
        try {
            $this->comment('Checking for local changes...');
            $status = $this->runLocalCommand(['git', 'status', '--porcelain']);

            if (empty($status)) {
                $this->info('✔ No changes to commit. Everything is up-to-date.');
                return Command::SUCCESS;
            }

            $this->comment('Changes detected. Proceeding with commit and push...');
            $commitMessage = $this->option('message');

            $this->line('Adding all changes...');
            $this->runLocalCommand(['git', 'add', '--all']);

            $this->line("Committing with message: '{$commitMessage}'");
            $this->runLocalCommand(['git', 'commit', '-m', $commitMessage]);

            $this->line('Pushing changes to the remote repository...');
            $pushOutput = $this->runLocalCommand(['git', 'push']);
            $this->info($pushOutput);

        } catch (ProcessFailedException $e) {
            $this->error('✘ An error occurred during the git process:');
            $this->error($e->getProcess()->getErrorOutput());

            return Command::FAILURE;
        }

        $this->info('✔ Project changes were successfully pushed.');
        return Command::SUCCESS;
    }
}
