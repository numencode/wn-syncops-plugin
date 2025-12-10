<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class Validate extends Command
{
    protected $signature = 'syncops:validate
        {--server= : Validate only the given server key from config/syncops.php}
        {--connect : Also attempt an SSH connection to each validated server}';

    protected $description = 'Validate SyncOps configuration (connections, SSH, project paths) and optionally test SSH connectivity.';

    public function handle(): int
    {
        $this->newLine();

        $connections = config('syncops.connections', []);
        $serverFilter = $this->option('server') ?: null;
        $withConnect = (bool) $this->option('connect');

        if (!is_array($connections) || empty($connections)) {
            $this->error('✘ No SyncOps connections defined in config/syncops.php.');
            return self::FAILURE;
        }

        if ($serverFilter !== null && !array_key_exists($serverFilter, $connections)) {
            $this->error("✘ No SyncOps connection found for server key '{$serverFilter}'.");
            return self::FAILURE;
        }

        $hasErrors = false;
        $hasConnectFailures = false;

        foreach ($connections as $name => $config) {
            if ($serverFilter !== null && $name !== $serverFilter) {
                continue;
            }

            $this->line("Validating server '{$name}'...");

            [$isValid, $errors, $warnings] = $this->validateConnection($name, $config);

            foreach ($errors as $message) {
                $this->error("  ✘ {$message}");
            }

            foreach ($warnings as $message) {
                $this->warn("  ⚠ {$message}");
            }

            if ($isValid) {
                $this->info("  ✔ Static configuration looks valid for '{$name}'.");
            } else {
                $hasErrors = true;
            }

            if ($withConnect && $isValid) {
                $this->line("  Testing SSH connectivity for '{$name}'...");

                try {
                    $executor = $this->createExecutor($name);
                    $executor->connectBoth();
                    $this->info("  ✔ SSH connectivity OK for '{$name}'.");
                } catch (\Throwable $e) {
                    $hasConnectFailures = true;
                    $this->error("  ✘ SSH connectivity failed for '{$name}': " . $e->getMessage());
                }
            }

            $this->newLine();
        }

        if ($hasErrors || $hasConnectFailures) {
            $this->error('✘ SyncOps validation completed with problems. Please review the errors above.');
            return self::FAILURE;
        }

        $this->info('✔ SyncOps configuration validation completed successfully. All checks passed.');
        return self::SUCCESS;
    }

    /**
     * Perform static validation on a single connection configuration.
     *
     * @param string $name   The connection name (key in config).
     * @param array  $config The connection configuration array.
     * @return array [bool $isValid, string[] $errors, string[] $warnings]
     */
    protected function validateConnection(string $name, array $config): array
    {
        $errors = [];
        $warnings = [];

        // SSH block
        if (!isset($config['ssh']) || !is_array($config['ssh'])) {
            $errors[] = "Connection '{$name}': missing 'ssh' configuration block.";
        } else {
            $ssh = $config['ssh'];

            if (empty($ssh['host'])) {
                $errors[] = "Connection '{$name}': 'ssh.host' is required.";
            }

            if (empty($ssh['username'])) {
                $errors[] = "Connection '{$name}': 'ssh.username' is required.";
            }

            $hasPassword = !empty($ssh['password']);
            $hasKeyPath = !empty($ssh['key_path']);

            if (!$hasPassword && !$hasKeyPath) {
                $warnings[] = "Connection '{$name}': neither 'ssh.password' nor 'ssh.key_path' is set.";
            }

            if ($hasKeyPath && is_string($ssh['key_path'])) {
                $keyPath = $ssh['key_path'];

                if (!file_exists($keyPath)) {
                    $warnings[] = "Connection '{$name}': SSH key file does not exist at '{$keyPath}'.";
                } elseif (!is_readable($keyPath)) {
                    $warnings[] = "Connection '{$name}': SSH key file is not readable at '{$keyPath}'.";
                }
            }
        }

        // Project block
        if (!isset($config['project']) || !is_array($config['project'])) {
            $errors[] = "Connection '{$name}': missing 'project' configuration block.";
        } else {
            $project = $config['project'];

            if (empty($project['path'])) {
                $errors[] = "Connection '{$name}': 'project.path' is required.";
            }

            if (array_key_exists('branch_main', $project) && $project['branch_main'] === '') {
                $errors[] = "Connection '{$name}': 'project.branch_main' is an empty string.";
            }

            if (array_key_exists('branch_prod', $project) && $project['branch_prod'] === '') {
                $errors[] = "Connection '{$name}': 'project.branch_prod' is an empty string.";
            }
        }

        // Database block (optional, but must be complete if partially defined)
        if (isset($config['database']) && is_array($config['database'])) {
            $db = $config['database'];

            $anyProvided = array_filter($db, static function ($value) {
                return $value !== null && $value !== '';
            });

            if (!empty($anyProvided)) {
                foreach (['database', 'username', 'password'] as $key) {
                    if (empty($db[$key])) {
                        $warnings[] = "Connection '{$name}': 'database.{$key}' is not set but database sync relies on it.";
                    }
                }
            }
        }

        // Permissions block (optional sanity check)
        if (isset($config['permissions']) && is_array($config['permissions'])) {
            $permissions = $config['permissions'];

            if (isset($permissions['web_folders'])
                && !is_string($permissions['web_folders'])
                && !is_array($permissions['web_folders'])
            ) {
                $warnings[] = "Connection '{$name}': 'permissions.web_folders' should be a string or array.";
            }
        }

        $isValid = empty($errors);

        return [$isValid, $errors, $warnings];
    }

    /**
     * Factory for creating a RemoteExecutor instance.
     * Separated for easier testing.
     */
    protected function createExecutor(string $server): RemoteExecutor
    {
        return new RemoteExecutor($server);
    }
}
