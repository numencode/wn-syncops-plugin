<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use NumenCode\SyncOps\Classes\SyncOpsCommand;

/**
 * Commits all changes in the project and pushes them. Meant for the server (e.g. a daily scheduled task),
 * so that changes made there (theme edits, static pages, ...) reach the Git repository.
 */
class ProjectCommit extends SyncOpsCommand
{
    protected $signature = 'syncops:project-commit
        {--message= : Commit message (default: "Server changes")}
        {--no-push  : Only commit, don\'t push}';

    protected $description = 'Commit all changes in the project and push them to the Git remote (e.g. on the server, from the scheduler).';

    protected function process(): int
    {
        $status = $this->git(['status', '--porcelain']);

        if (trim($status) === '') {
            $this->info('✔ There are no changes to commit.');

            return self::SUCCESS;
        }

        $count = count(explode("\n", trim($status)));
        $message = (string) ($this->option('message') ?: 'Server changes');

        $this->step("Committing {$count} changed file(s)", function () use ($message): void {
            $this->git(['add', '--all']);
            $this->git(['commit', '-m', $message]);
        });

        if (!$this->option('no-push')) {
            $this->step('Pushing', fn () => $this->git(['push']));
        }

        $this->info('✔ The changes are committed' . ($this->option('no-push') ? '.' : ' and pushed.'));

        return self::SUCCESS;
    }
}
