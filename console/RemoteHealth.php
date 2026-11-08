<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use NumenCode\SyncOps\Classes\DatabaseDump;
use NumenCode\SyncOps\Classes\RemoteServer;
use NumenCode\SyncOps\Classes\SyncOpsCommand;

class RemoteHealth extends SyncOpsCommand
{
    protected $signature = 'syncops:remote-health
        {server : The connection name from config/syncops.php}
        {--full : Also list PHP extensions and test the database login}';

    protected $description = 'Show the health of a server: system, PHP, Composer, database and the project.';

    protected bool $failed = false;

    protected function process(): int
    {
        $server = $this->server((string) $this->argument('server'));
        $full = (bool) $this->option('full');
        $this->newLine();

        $this->section('System');
        $this->check($server, 'Uptime', ['uptime']);
        $this->check($server, 'Disk (project)', ['df', '-h', '.'], static fn (string $out) => trim(explode("\n", trim($out))[1] ?? $out));

        $this->section('PHP and Composer');
        $this->check($server, 'PHP', [...$server->php(), '-v'], static fn (string $out) => strtok($out, "\n") ?: '');
        $this->check($server, 'Composer', [...$server->composer(), '--version', '--no-ansi'], static fn (string $out) => strtok($out, "\n") ?: '');

        if ($full) {
            $this->check($server, 'Extensions', [...$server->php(), '-r', 'echo implode(", ", get_loaded_extensions());']);
        }

        $this->section('Database');
        $this->checkDatabase($server, $full);

        $this->section('Project');
        $this->line('  Path: ' . $server->projectPath());
        $this->check($server, 'Git branch', ['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        $status = $server->run(['git', 'status', '--porcelain']);

        if ($status->successful()) {
            $changes = trim($status->output) === '' ? 0 : count(explode("\n", trim($status->output)));
            $changes === 0
                ? $this->info('  ✔ Git: working tree is clean')
                : $this->warn("  ⚠ Git: {$changes} uncommitted change(s); project-deploy will refuse to run (see project-pull)");
        }

        $this->check($server, 'Framework', [...$server->php(), 'artisan', '--version', '--no-ansi']);
        $this->check($server, 'Winter CMS', [...$server->php(), 'artisan', 'winter:version', '--no-ansi'], static function (string $out): string {
            preg_match('/Winter CMS build ([\d.]+)/', $out, $match);

            return isset($match[1]) ? rtrim($match[1], '.') : trim($out);
        });

        $this->newLine();

        if ($this->failed) {
            $this->error('✘ Some checks failed, see above.');

            return self::FAILURE;
        }

        $this->info('✔ All checks passed.');

        return self::SUCCESS;
    }

    protected function section(string $title): void
    {
        $this->line("<options=bold>{$title}</>");
    }

    /**
     * @param array<int, string> $command
     */
    protected function check(RemoteServer $server, string $label, array $command, ?callable $format = null): void
    {
        $result = $server->run($command);

        if ($result->successful()) {
            $value = trim($format !== null ? $format($result->output) : $result->output);
            $this->info("  ✔ {$label}: {$value}");

            return;
        }

        $this->failed = true;
        $this->error("  ✘ {$label}: " . $this->redactor->redact(trim($result->error ?: $result->output) ?: "exit code {$result->exitCode}"));
    }

    protected function checkDatabase(RemoteServer $server, bool $full): void
    {
        $client = trim($server->runShell('command -v mariadb || command -v mysql', null, false)->output);

        if ($client === '') {
            $this->warn('  ⚠ No mariadb/mysql client found on the server.');

            return;
        }

        $this->check($server, 'Client', [$client, '--version'], static fn (string $out) => trim($out));
        $database = $server->config->database;

        if ($database === null) {
            $this->comment('  No database configured for this connection (database.database, database.username).');

            return;
        }

        if (!$full) {
            return;
        }

        $temp = $server->makeTempDirectory();
        $this->onCleanup(static fn () => $server->remove($temp));
        $server->putContents($temp . '/client.cnf', DatabaseDump::optionFile($database));

        $this->check(
            $server,
            "Login to '{$database['database']}'",
            [$client, '--defaults-extra-file=' . $temp . '/client.cnf', '-N', '-e', 'SELECT VERSION()', $database['database']]
        );
    }
}
