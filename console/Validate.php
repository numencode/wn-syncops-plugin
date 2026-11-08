<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Console;

use NumenCode\SyncOps\Classes\ConnectionConfig;
use NumenCode\SyncOps\Classes\RemoteServer;
use NumenCode\SyncOps\Classes\SyncOpsCommand;
use Throwable;

class Validate extends SyncOpsCommand
{
    protected $signature = 'syncops:validate
        {--server= : Validate only this connection}
        {--connect : Also connect to each server and check the project, PHP and Composer there}';

    protected $description = 'Validate the SyncOps connections in config/syncops.php and optionally test them.';

    protected function process(): int
    {
        $connections = config('syncops.connections', []);
        $only = $this->option('server') ?: null;

        if (!is_array($connections) || $connections === []) {
            $this->error('✘ No connections are defined in config/syncops.php.');

            return self::FAILURE;
        }

        if ($only !== null && !array_key_exists($only, $connections)) {
            $this->error("✘ There is no connection named '{$only}'.");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($connections as $name => $raw) {
            if ($only !== null && $name !== $only) {
                continue;
            }

            $this->line("<options=bold>{$name}</>");
            [$errors, $warnings] = ConnectionConfig::check((string) $name, is_array($raw) ? $raw : []);

            foreach ($errors as $error) {
                $this->error("  ✘ {$error}");
            }

            foreach ($warnings as $warning) {
                $this->warn("  ⚠ {$warning}");
            }

            if ($errors !== []) {
                $failed = true;
                $this->newLine();
                continue;
            }

            $this->info('  ✔ Configuration is valid.');

            if ($this->option('connect') && !$this->checkServer((string) $name)) {
                $failed = true;
            }

            $this->newLine();
        }

        if ($failed) {
            $this->error('✘ Validation found problems.');

            return self::FAILURE;
        }

        $this->info('✔ All checks passed.');

        return self::SUCCESS;
    }

    protected function checkServer(string $name): bool
    {
        try {
            $config = ConnectionConfig::fromConfig($name);
            $this->redactor->add($config->secrets());

            /** @var RemoteServer $server */
            $server = app()->make(RemoteServer::class, ['config' => $config]);
            $server->connect();

            $this->info('  ✔ SSH login works.');
            $this->line('  Host key fingerprint: ' . $server->fingerprint()
                . ($config->hostFingerprint === null ? ' <comment>(not pinned: set ssh.host_fingerprint)</comment>' : ' <info>(verified)</info>'));

            $checks = [
                'Project path'  => [['test', '-f', 'artisan'], 'contains no Winter project (artisan not found)'],
                'Git'           => [['git', 'rev-parse', '--abbrev-ref', 'HEAD'], 'is not a Git repository'],
                'PHP'           => [[...$server->php(), '-r', 'echo PHP_VERSION;'], "'{$config->php}' does not work"],
                'Composer'      => [[...$server->composer(), '--version', '--no-ansi'], "'{$config->composer}' does not work"],
            ];

            $ok = true;

            foreach ($checks as $label => [$command, $problem]) {
                $result = $server->run($command);

                if ($result->successful()) {
                    $value = trim(strtok($result->output, "\n") ?: '');
                    $this->info("  ✔ {$label}" . ($value !== '' ? ": {$value}" : ''));
                } else {
                    $this->error("  ✘ {$label}: {$problem}.");
                    $ok = false;
                }
            }

            $server->disconnect();

            return $ok;
        } catch (Throwable $e) {
            $this->error('  ✘ ' . $this->redactor->redact($e->getMessage()));

            return false;
        }
    }
}
