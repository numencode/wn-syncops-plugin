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

    protected $description = 'Commits untracked changes on the remote server, pushes them to the origin,
                              and optionally merges them into the local branch.';

    public function handle(RemoteExecutor $executor)
    {
        try {
            $this->comment("Connecting to remote server '{$this->argument('server')}'...");
            $executor->connect($this->argument('server'));

            if ($executor->remoteIsClean()) {
                $this->info('✔ No changes on the remote server.');
                return Command::SUCCESS;
            }

            $this->comment('Changes detected on remote. Committing and pushing...');
            $commitMessage = $this->option('message') ?: 'Server changes';

            $remoteCommands = [
                ['git', 'add', '--all'],
                ['git', 'commit', '-m', $commitMessage],
            ];

            if ($this->option('pull')) {
                $this->line('Pulling new changes on the remote...');
                $remoteCommands[] = ['git', 'pull'];
            }

            $currentRemoteBranch = $executor->runAndGet(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
            $this->line("Pushing remote changes from '{$currentRemoteBranch}' to origin...");
            $remoteCommands[] = ['git', 'push', 'origin', $currentRemoteBranch];

            $executor->runAndPrint($remoteCommands);

            if ($this->option('no-merge')) {
                $this->info(PHP_EOL . '✔ Remote changes were pushed. Skipping local merge as requested.');
                return Command::SUCCESS;
            }

            $this->comment('Fetching and merging changes locally...');
            $this->runLocalCommand(['git', 'fetch', 'origin']);

            $mergeBranch = $executor->config['branch_prod'] ?? $currentRemoteBranch;
            $this->info($this->runLocalCommand(['git', 'merge', 'origin/' . $mergeBranch]));

        } catch (ProcessFailedException $e) { // Catches local command failures
            $this->error('✘ A local git command failed:');
            $this->error($e->getProcess()->getErrorOutput());
            return Command::FAILURE;
        } catch (\Exception $e) { // Catches remote executor failures or other issues
            $this->error("✘ An error occurred on server '{$this->argument('server')}':");
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->info(PHP_EOL . '✔ Changes were successfully pulled and merged into the local project.');
        return Command::SUCCESS;
    }
}
