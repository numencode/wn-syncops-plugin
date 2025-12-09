<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;
use NumenCode\SyncOps\Traits\RunsLocalCommands;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ProjectPull extends Command
{
    use RunsLocalCommands;

    protected $signature = 'syncops:project-pull
        {server       : The name of the remote server}
        {--m|no-merge : Do not merge changes into the local branch automatically}
        {--p|pull     : Execute "git pull" on the remote before pushing changes}
        {--message=   : Commit message for server changes (default: "Server changes")}';

    protected $description = 'Commit untracked changes on the remote server, push them to the origin, and optionally merge them into the local branch.';

    public function handle(): int
    {
        $this->newLine();

        $server = $this->argument('server');

        try {
            $this->line("Connecting to remote server '{$server}'...");
            $this->newLine();

            $executor = new RemoteExecutor($server);

            if ($executor->ssh->remoteIsClean()) {
                $this->info('✔ No changes on the remote server.');
                return self::SUCCESS;
            }

            $this->line('Changes detected on remote. Committing and pushing...');
            $commitMessage = $this->option('message') ?: 'Server changes';

            $remoteCommands = [
                ['git', 'add', '--all'],
                ['git', 'commit', '-m', $commitMessage],
            ];

            if ($this->option('pull')) {
                $this->line('Pulling new changes on the remote (before pushing)...');
                $remoteCommands[] = ['git', 'pull'];
            }

            $currentRemoteBranch = $executor->ssh->runAndGet(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
            $this->line("Pushing remote changes from '{$currentRemoteBranch}' to origin...");
            $remoteCommands[] = ['git', 'push', 'origin', $currentRemoteBranch];

            $executor->ssh->runAndPrint($remoteCommands);

            if ($this->option('no-merge')) {
                $this->newLine();
                $this->info('✔ Remote changes were pushed. Skipping local merge as requested.');
                return self::SUCCESS;
            }

            $this->line('Fetching and merging changes locally...');
            $this->runLocalCommand('git fetch origin');

            $mergeBranch = $executor->config['project']['branch_prod'] ?? $currentRemoteBranch;
            $mergeOutput = $this->runLocalCommand('git merge origin/' . $mergeBranch);

            if ($mergeOutput !== '') {
                $this->line($mergeOutput);
            }
        } catch (ProcessFailedException $e) { // local git failures
            $this->newLine();
            $this->error('✘ A local git command failed:');
            $this->error($e->getProcess()->getErrorOutput() ?: $e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) { // remote executor failures or anything else
            $this->newLine();
            $this->error("✘ An error occurred on server '{$server}':");
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✔ Changes were successfully pulled and merged into the local project.');
        return self::SUCCESS;
    }
}
