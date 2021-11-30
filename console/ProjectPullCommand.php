<?php namespace NumenCode\Backup\Console;

class ProjectPullCommand extends RemoteCommand
{
    protected $signature = 'project:pull
        {server         : The name of the remote server}
        {--p|--pull     : Execute "git pull" command before "git push"}
        {--m|--no-merge : Do not merge changes automatically}';

    protected $description = 'Fetch changes from production environment and merge them into the local project.';

    public function handle()
    {
        if (!$this->sshConnect()) {
            return $this->error('An error occurred while connecting with SSH.');
        }

        $this->line('');

        if ($this->checkForChanges()) {
            $this->line('');
            $this->alert('No changes on a remote server.');

            return false;
        }

        $this->question('Committing the changes:');
        $this->sshRunAndPrint([
            'git add --all',
            'git commit -m "Server changes"',
        ]);

        if ($this->option('pull')) {
            $this->question('Pulling new changes:');
            $this->sshRunAndPrint([
                'git pull',
            ]);
        }

        $this->question('Pushing the changes:');
        $this->sshRunAndPrint([
            'git push origin ' . $this->backup['branch'],
        ]);

        if (!$this->option('no-merge')) {
            $this->question('Merging the changes:');
            $this->info(shell_exec('git fetch'));
            $this->info(shell_exec('git merge origin/' . $this->backup['branch']));
        }

        $this->alert('Changes were successfully pulled into the project.');
    }
}
