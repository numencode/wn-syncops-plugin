<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use NumenCode\SyncOps\Classes\CommandResult;
use NumenCode\SyncOps\Classes\RemoteServer;
use NumenCode\SyncOps\Classes\SyncOpsCommand;
use RuntimeException;

/**
 * Deploys a project on a server with Git:
 *
 *  1. maintenance mode on (not with --fast)
 *  2. merge origin/<branch_main> into the checked-out <branch_prod> and push it (or "git pull" when branch_main
 *     is false); on a conflict the merge is aborted and the server stays as it was
 *  3. composer install when composer.lock changed (or --composer), then migrations (or --migrate)
 *  4. cache:clear, file ownership
 *  5. maintenance mode off – always, also after an error
 */
class ProjectDeploy extends SyncOpsCommand
{
    protected $signature = 'syncops:project-deploy
        {server       : The connection name from config/syncops.php}
        {--f|fast     : Don\'t put the application into maintenance mode}
        {--c|composer : Run composer install even if composer.lock didn\'t change}
        {--m|migrate  : Run the migrations even if Composer didn\'t run}
        {--x|sudo     : Run artisan and chown with "sudo -n"}
        {--dry-run    : Only show the steps and commands, without changing anything}';

    protected $description = 'Deploy the project to a server with Git (merge, Composer, migrations, cache, maintenance mode).';

    protected RemoteServer $remote;

    protected function process(): int
    {
        $name = (string) $this->argument('server');
        $this->remote = $server = $this->server($name);
        $config = $server->config;

        $branch = trim($server->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])->throwIfFailed('Reading the branch on the server')->output);

        if ($config->branchProd !== null && $branch !== $config->branchProd) {
            $this->error("✘ The server is on branch '{$branch}', but '{$config->branchProd}' is configured (project.branch_prod). Not deploying.");

            return self::FAILURE;
        }

        if (trim($server->run(['git', 'status', '--porcelain'])->throwIfFailed('Reading the Git status on the server')->output) !== '') {
            $this->error("✘ There are uncommitted changes on '{$name}'. Not deploying.");
            $this->line("  Bring them into Git first: php artisan syncops:project-pull {$name}");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($branch);
        }

        $before = trim($server->run(['git', 'rev-parse', 'HEAD'])->throwIfFailed('Reading the current commit')->output);

        if (!$this->option('fast')) {
            $this->step('Maintenance mode on', fn () => $this->artisan(['down']));
            $this->onCleanup(function (): void {
                $this->line('Maintenance mode off...');
                $this->artisan(['up']);
            });
        }

        if ($config->rootUser !== null) {
            $this->step("Ownership of the project for {$config->rootUser}", fn () => $this->sudo(['chown', '-R', $config->rootUser, '.'])->throwIfFailed('chown'));
        }

        $this->step($config->branchMain === false ? 'git pull' : "Merging origin/{$config->branchMain} into {$branch}", fn () => $this->updateCode($branch));

        $after = trim($server->run(['git', 'rev-parse', 'HEAD'])->throwIfFailed('Reading the new commit')->output);
        $changed = $before === $after ? [] : array_filter(explode("\n", trim($server->run(['git', 'diff', '--name-only', $before, $after])->output)));
        if ($before !== $after) {
            $this->line('  ' . count($changed) . ' file(s) changed.');
        }

        $composer = $this->option('composer') || in_array('composer.lock', $changed, true);

        if ($composer) {
            $this->step('composer install', fn () => $server->run(
                [...$server->composer(), 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader', '--no-ansi'],
                $this->liveOutput()
            )->throwIfFailed('composer install'));

            $this->restoreFileModes();
        }

        if ($composer || $this->option('migrate')) {
            $this->step('Migrations (winter:up)', fn () => $this->artisan(['winter:up'], true));
        }

        $this->step('Clearing the cache', fn () => $this->artisan(['cache:clear']));
        $this->setOwnership();

        $this->newLine();
        $this->info("✔ Deployed '{$name}'" . ($before === $after ? ' (no new commits).' : ': ' . substr($before, 0, 7) . ' → ' . substr($after, 0, 7) . '.'));

        return self::SUCCESS;
    }

    /**
     * Merges origin/<branch_main> and pushes <branch_prod>, or pulls. A failed merge is aborted.
     */
    protected function updateCode(string $branch): void
    {
        $server = $this->remote;
        $branchMain = $server->config->branchMain;

        if ($branchMain === false) {
            $result = $server->run(['git', 'pull', '--no-edit'], $this->liveOutput());
        } else {
            $server->run(['git', 'fetch', 'origin'])->throwIfFailed('git fetch');
            $result = $server->run(['git', 'merge', '--no-edit', 'origin/' . $branchMain], $this->liveOutput());
        }

        if (!$result->successful()) {
            $server->run(['git', 'merge', '--abort']);

            throw new RuntimeException(
                'Merging failed (conflicts?), so the merge was aborted and the server is unchanged. '
                . 'Resolve it locally, e.g. merge ' . ($branchMain ?: 'the branch') . " into {$branch} and push, then deploy again."
            );
        }

        if ($branchMain !== false) {
            $server->run(['git', 'push', 'origin', $branch])->throwIfFailed("git push origin {$branch}");
        }
    }

    /**
     * Composer re-creates tracked files (e.g. Winter's modules/) with its own file modes. Mode-only changes
     * are reverted, so the server stays clean for the next deployment; content changes are only reported.
     */
    protected function restoreFileModes(): void
    {
        $server = $this->remote;
        $summary = $server->run(['git', 'diff', '--summary'])->output;
        preg_match_all('/^\s*mode change \d+ => \d+ (.+)$/m', $summary, $matches);
        $modeOnly = $matches[1];

        if ($modeOnly !== []) {
            // Files whose content changed (mode changes ignored) are kept as they are
            $contentChanged = array_filter(explode("\n", trim($server->run(['git', '-c', 'core.fileMode=false', 'diff', '--name-only'])->output)));
            $restore = array_values(array_diff($modeOnly, $contentChanged));

            foreach (array_chunk($restore, 100) as $chunk) {
                $server->run(['git', 'checkout', '--', ...$chunk])->throwIfFailed('Restoring file modes');
            }

            $this->comment('  Restored the Git file mode of ' . count($restore) . ' file(s) changed by Composer.');
        }

        // Only tracked files (untracked ones, e.g. the maintenance mode files, are not Composer's)
        $left = trim($server->run(['git', 'status', '--porcelain', '--untracked-files=no'])->output);

        if ($left !== '') {
            $this->warn('⚠ Composer changed tracked files on the server:');
            $this->line($left);
        }
    }

    protected function setOwnership(): void
    {
        $config = $this->remote->config;

        if ($config->webUser === null || $config->webFolders === []) {
            return;
        }

        $this->step(
            "Ownership of " . implode(', ', $config->webFolders) . " for {$config->webUser}",
            fn () => $this->sudo(['chown', '-R', $config->webUser, ...$config->webFolders])->throwIfFailed('chown')
        );
    }

    /**
     * @param array<int, string> $arguments
     */
    protected function artisan(array $arguments, bool $live = false): void
    {
        $this->sudo([...$this->remote->php(), 'artisan', ...$arguments, '--no-interaction'], $live ? $this->liveOutput() : null)
            ->throwIfFailed('php artisan ' . $arguments[0]);
    }

    /**
     * Runs a command, with "sudo -n" when --sudo is given (never waits for a password).
     *
     * @param array<int, string> $command
     */
    protected function sudo(array $command, ?callable $onOutput = null): CommandResult
    {
        return $this->remote->run($this->option('sudo') ? ['sudo', '-n', ...$command] : $command, $onOutput);
    }

    protected function dryRun(string $branch): int
    {
        $server = $this->remote;
        $config = $server->config;
        $sudo = $this->option('sudo') ? 'sudo -n ' : '';
        $artisan = $sudo . implode(' ', $server->php()) . ' artisan';
        $steps = [];

        if (!$this->option('fast')) {
            $steps[] = "{$artisan} down --no-interaction";
        }

        if ($config->rootUser !== null) {
            $steps[] = "{$sudo}chown -R {$config->rootUser} .";
        }

        if ($config->branchMain === false) {
            $steps[] = 'git pull --no-edit   (on failure: git merge --abort, stop)';
        } else {
            $steps[] = 'git fetch origin';
            $steps[] = "git merge --no-edit origin/{$config->branchMain}   (on failure: git merge --abort, stop)";
            $steps[] = "git push origin {$branch}";
        }

        $server->run(['git', 'fetch', 'origin']);
        $incoming = $config->branchMain === false ? '' : trim($server->run(['git', 'diff', '--name-only', 'HEAD...origin/' . $config->branchMain])->output);
        $lockChanges = in_array('composer.lock', explode("\n", $incoming), true);
        $composer = $this->option('composer') || $lockChanges;

        $steps[] = implode(' ', $server->composer()) . ' install --no-dev --no-interaction --prefer-dist --optimize-autoloader'
            . ($composer ? '' : '   (skipped: composer.lock unchanged)');
        $steps[] = "{$artisan} winter:up --no-interaction" . ($composer || $this->option('migrate') ? '' : '   (skipped: no Composer run, no --migrate)');
        $steps[] = "{$artisan} cache:clear --no-interaction";

        if ($config->webUser !== null && $config->webFolders !== []) {
            $steps[] = "{$sudo}chown -R {$config->webUser} " . implode(' ', $config->webFolders);
        }

        if (!$this->option('fast')) {
            $steps[] = "{$artisan} up --no-interaction   (always, also after an error)";
        }

        $count = $incoming === '' ? 0 : count(explode("\n", $incoming));
        $this->line("Dry run for '{$server->config->name}' (branch {$branch}" . ($config->branchMain !== false ? ", {$count} file(s) incoming from origin/{$config->branchMain}" : '') . '):');

        foreach ($steps as $index => $step) {
            $this->line('  ' . ($index + 1) . '. ' . $step);
        }

        $this->newLine();
        $this->info('✔ Dry run: nothing was changed.');

        return self::SUCCESS;
    }
}
