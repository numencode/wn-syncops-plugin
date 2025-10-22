<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Traits\RunsLocalCommands;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ProjectPush extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:project-push
        {--message= : Commit message for local changes (default: "Server changes")}';

    protected $description = 'Add and commit project changes locally and push them to the remote repository.';

    public function handle(): int
    {
        $this->newLine();

        try {
            $this->line("Checking for local changes...");
            $status = $this->runLocalCommand('git status --porcelain');

            if (empty($status)) {
                $this->newLine();
                $this->info("✔ No changes to commit. Everything is up-to-date.");
                return self::SUCCESS;
            }

            $this->warn("Changes detected. Proceeding with commit and push...");
            $commitMessage = $this->option('message') ?: 'Server changes';

            $this->newLine();
            $this->line("Adding all changes...");
            $this->runLocalCommand('git add --all');

            $this->line("Committing with message: '{$commitMessage}'");
            $this->runLocalCommand('git commit -m "' . $commitMessage . '"');

            $this->line("Pushing changes to the remote repository...");
            $this->runLocalCommand('git push');
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("✘ An error occurred during the git process:");

            if ($e instanceof ProcessFailedException) {
                $this->error($e->getProcess()->getErrorOutput());
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("✔ Project changes were successfully pushed.");
        return self::SUCCESS;
    }
}
