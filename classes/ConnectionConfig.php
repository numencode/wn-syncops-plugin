<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Classes;

use RuntimeException;

/**
 * A validated remote connection from config/syncops.php ("connections.<name>"), with defaults for every
 * optional key, so configuration files from SyncOps 1.x keep working.
 */
final class ConnectionConfig
{
    /**
     * @param array<int, string> $webFolders
     * @param array<string, mixed>|null $database database, username, password, host, port, tables
     */
    public function __construct(
        public readonly string $name,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly ?string $password,
        public readonly ?string $keyPath,
        public readonly ?string $keyPassphrase,
        public readonly ?string $hostFingerprint,
        public readonly int $timeout,
        public readonly string $path,
        public readonly ?string $branchProd,
        public readonly string|false $branchMain,
        public readonly string $php,
        public readonly string $composer,
        public readonly ?string $tempPath,
        public readonly ?string $rootUser,
        public readonly ?string $webUser,
        public readonly array $webFolders,
        public readonly ?array $database,
    ) {
    }

    /**
     * Loads and validates a connection; throws with all problems when it is not usable.
     */
    public static function fromConfig(string $name): self
    {
        $raw = config('syncops.connections.' . $name);

        if (!is_array($raw) || $raw === []) {
            throw new RuntimeException("No SyncOps connection named '{$name}' in config/syncops.php.");
        }

        [$errors] = self::check($name, $raw);

        if ($errors !== []) {
            throw new RuntimeException("Invalid SyncOps connection '{$name}':\n- " . implode("\n- ", $errors));
        }

        $ssh = $raw['ssh'];
        $project = $raw['project'];
        $permissions = is_array($raw['permissions'] ?? null) ? $raw['permissions'] : [];
        $branchMain = $project['branch_main'] ?? 'main';

        return new self(
            name: $name,
            host: (string) $ssh['host'],
            port: (int) ($ssh['port'] ?? 22) ?: 22,
            username: (string) $ssh['username'],
            password: self::stringOrNull($ssh['password'] ?? null),
            keyPath: self::stringOrNull($ssh['key_path'] ?? null),
            keyPassphrase: self::stringOrNull($ssh['key_passphrase'] ?? null),
            hostFingerprint: self::stringOrNull($ssh['host_fingerprint'] ?? null),
            timeout: max(0, (int) ($ssh['timeout'] ?? 0)),
            path: rtrim((string) $project['path'], '/'),
            branchProd: self::stringOrNull($project['branch_prod'] ?? null),
            branchMain: $branchMain === false ? false : ((string) $branchMain ?: 'main'),
            php: self::stringOrNull($project['php'] ?? null) ?? 'php',
            composer: self::stringOrNull($project['composer'] ?? null) ?? 'composer',
            tempPath: self::stringOrNull($project['temp_path'] ?? null),
            rootUser: self::stringOrNull($permissions['root_user'] ?? null),
            webUser: self::stringOrNull($permissions['web_user'] ?? null),
            webFolders: self::folders($permissions['web_folders'] ?? []),
            database: self::database($raw['database'] ?? null),
        );
    }

    /**
     * Static checks of a raw connection array.
     *
     * @param array<string, mixed> $raw
     * @return array{0: array<int, string>, 1: array<int, string>} [errors, warnings]
     */
    public static function check(string $name, array $raw): array
    {
        $errors = [];
        $warnings = [];
        $ssh = $raw['ssh'] ?? null;
        $project = $raw['project'] ?? null;

        if (!is_array($ssh)) {
            $errors[] = "missing the 'ssh' block";
        } else {
            foreach (['host', 'username'] as $key) {
                if (empty($ssh[$key])) {
                    $errors[] = "'ssh.{$key}' is required";
                }
            }

            if (empty($ssh['password']) && empty($ssh['key_path'])) {
                $errors[] = "set 'ssh.key_path' (recommended) or 'ssh.password'";
            }

            if (!empty($ssh['key_path']) && !is_readable((string) $ssh['key_path'])) {
                $errors[] = "the SSH key '{$ssh['key_path']}' does not exist or is not readable";
            }

            if (!empty($ssh['host_fingerprint']) && !str_starts_with((string) $ssh['host_fingerprint'], 'SHA256:')) {
                $errors[] = "'ssh.host_fingerprint' must look like 'SHA256:…' (as shown by `ssh-keygen -lf` or `syncops:validate --connect`)";
            }

            if (empty($ssh['host_fingerprint'])) {
                $warnings[] = "'ssh.host_fingerprint' is not set, so the server's identity is not verified";
            }
        }

        if (!is_array($project)) {
            $errors[] = "missing the 'project' block";
        } elseif (empty($project['path'])) {
            $errors[] = "'project.path' is required";
        }

        $database = self::database($raw['database'] ?? null);
        $rawDatabase = is_array($raw['database'] ?? null) ? $raw['database'] : [];
        $credentials = array_filter([$rawDatabase['database'] ?? null, $rawDatabase['username'] ?? null]);

        if ($database === null && $credentials !== []) {
            $warnings[] = "'database.database' and 'database.username' are both needed for the database commands";
        }

        return [$errors, $warnings];
    }

    /**
     * Whether the remote database is configured (db-pull, remote-health).
     */
    public function hasDatabase(): bool
    {
        return $this->database !== null;
    }

    /**
     * Secret values to hide in any output.
     *
     * @return array<int, string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([
            $this->password,
            $this->keyPassphrase,
            $this->database['password'] ?? null,
        ], static fn ($value) => is_string($value) && $value !== ''));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, string>
     */
    private static function folders(mixed $folders): array
    {
        if (is_string($folders)) {
            $folders = explode(',', $folders);
        }

        if (!is_array($folders)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn ($folder) => trim((string) $folder), $folders))));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function database(mixed $database): ?array
    {
        if (!is_array($database) || empty($database['database']) || empty($database['username'])) {
            return null;
        }

        return [
            'database' => (string) $database['database'],
            'username' => (string) $database['username'],
            'password' => (string) ($database['password'] ?? ''),
            'host'     => self::stringOrNull($database['host'] ?? null),
            'port'     => self::stringOrNull($database['port'] ?? null),
            'tables'   => array_values(array_filter((array) ($database['tables'] ?? []), 'is_string')),
        ];
    }
}
