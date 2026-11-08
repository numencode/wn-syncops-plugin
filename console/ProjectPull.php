<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use NumenCode\SyncOps\Classes\SyncOpsCommand;

class ProjectPull extends SyncOpsCommand
{
    protected $signature = 'syncops:project-pull
        {server       : The connection name from config/syncops.php}
        {--m|no-merge : Don\'t merge the changes into the local branch}
        {--p|pull     : Run "git pull" on the server before pushing its changes}
        {--message=   : Commit message for the server\'s changes (default: "Server changes")}';

    protected $description = 'Commit the changes made on a server, push them to the Git remote and merge them locally.';

    protected function process(): int
    {
        $name = (string) $this->argument('server');
        $server = $this->server($name);

        $status = $server->run(['git', 'status', '--porcelain'])->throwIfFailed('Reading the Git status on the server');

        if (trim($status->output) === '') {
            $this->info("✔ There are no changes on '{$name}'.");

            return self::SUCCESS;
        }

        $this->line(count(explode("\n", trim($status->output))) . " changed file(s) on '{$name}'.");
        $branch = trim($server->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])->throwIfFailed('Reading the branch on the server')->output);
        $message = (string) ($this->option('message') ?: 'Server changes');

        $this->step('Committing on the server', static function () use ($server, $message): void {
            $server->run(['git', 'add', '--all'])->throwIfFailed('git add');
            $server->run(['git', 'commit', '-m', $message])->throwIfFailed('git commit');
        });

        if ($this->option('pull')) {
            $this->step('Pulling on the server', function () use ($server): void {
                $result = $server->run(['git', 'pull', '--no-edit'], $this->liveOutput());

                if (!$result->successful()) {
                    $server->run(['git', 'merge', '--abort']);
                    $result->throwIfFailed('git pull (the merge was aborted; the server\'s commit is kept)');
                }
            });
        }

        $this->step("Pushing '{$branch}' from the server", static fn () => $server->run(['git', 'push', 'origin', $branch])->throwIfFailed('git push'));

        if ($this->option('no-merge')) {
            $this->info("✔ The server's changes are pushed to origin/{$branch}.");

            return self::SUCCESS;
        }

        $mergeBranch = $server->config->branchProd ?? $branch;

        $this->step("Merging origin/{$mergeBranch} locally", function () use ($mergeBranch): void {
            $this->git(['fetch', 'origin']);
            $output = $this->git(['merge', '--no-edit', 'origin/' . $mergeBranch]);
            $this->line(trim($output));
        });

        $this->info("✔ The server's changes are committed, pushed and merged locally.");

        return self::SUCCESS;
    }
}
