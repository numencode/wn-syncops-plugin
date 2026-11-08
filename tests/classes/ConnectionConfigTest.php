<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Tests\Classes;

use NumenCode\SyncOps\Classes\ConnectionConfig;
use NumenCode\SyncOps\Tests\SyncOpsTestCase;
use RuntimeException;

class ConnectionConfigTest extends SyncOpsTestCase
{
    public function testAVersion1ConfigGetsDefaults(): void
    {
        // A 1.x connection: none of the 2.0 keys
        config(['syncops.connections.old' => [
            'ssh'         => ['host' => 'server.test', 'port' => '2222', 'username' => 'deploy', 'password' => 'pw', 'key_path' => ''],
            'project'     => ['path' => '/srv/project/', 'branch_prod' => 'prod', 'branch_main' => 'main'],
            'permissions' => ['root_user' => null, 'web_user' => 'www-data:www-data', 'web_folders' => 'storage, themes'],
            'database'    => ['database' => null, 'username' => null, 'password' => null, 'tables' => []],
        ]]);

        $config = ConnectionConfig::fromConfig('old');

        $this->assertSame(2222, $config->port);
        $this->assertSame('pw', $config->password);
        $this->assertNull($config->keyPath);
        $this->assertSame('/srv/project', $config->path, 'Trailing slash removed');
        $this->assertSame('php', $config->php);
        $this->assertSame('composer', $config->composer);
        $this->assertSame(0, $config->timeout);
        $this->assertNull($config->hostFingerprint);
        $this->assertSame(['storage', 'themes'], $config->webFolders);
        $this->assertFalse($config->hasDatabase());
    }

    public function testReadsTheNewSettings(): void
    {
        $this->useConnection([
            'ssh'     => ['key_passphrase' => 'phrase', 'timeout' => '600'],
            'project' => ['php' => 'php8.4', 'composer' => 'php8.4 /usr/bin/composer', 'temp_path' => '/home/deploy/tmp', 'branch_main' => false],
        ]);

        $config = ConnectionConfig::fromConfig('test');

        $this->assertSame('phrase', $config->keyPassphrase);
        $this->assertSame(600, $config->timeout);
        $this->assertSame('php8.4', $config->php);
        $this->assertSame('php8.4 /usr/bin/composer', $config->composer);
        $this->assertSame('/home/deploy/tmp', $config->tempPath);
        $this->assertFalse($config->branchMain, 'false means deploying with "git pull"');
        $this->assertSame('remote_db', $config->database['database']);
    }

    public function testSecretsContainAllPasswords(): void
    {
        $this->useConnection(['ssh' => ['password' => 'ssh-pw', 'key_passphrase' => 'phrase']]);

        $this->assertSame(['ssh-pw', 'phrase', 'Secret"Pa$$&word'], ConnectionConfig::fromConfig('test')->secrets());
    }

    public function testUnknownConnectionsFail(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No SyncOps connection named 'missing'");

        ConnectionConfig::fromConfig('missing');
    }

    public function testInvalidConnectionsListAllProblems(): void
    {
        config(['syncops.connections.bad' => [
            'ssh'     => ['host' => '', 'username' => 'deploy', 'key_path' => '/does/not/exist', 'host_fingerprint' => 'abc'],
            'project' => ['path' => ''],
        ]]);

        try {
            ConnectionConfig::fromConfig('bad');
            $this->fail('An invalid connection must not load');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("'ssh.host' is required", $e->getMessage());
            $this->assertStringContainsString('/does/not/exist', $e->getMessage());
            $this->assertStringContainsString("'ssh.host_fingerprint' must look like 'SHA256:…'", $e->getMessage());
            $this->assertStringContainsString("'project.path' is required", $e->getMessage());
        }
    }

    public function testCheckWarnsAboutMissingFingerprintAndIncompleteDatabase(): void
    {
        $connection = $this->connection();
        unset($connection['ssh']['host_fingerprint']);
        $connection['database'] = ['database' => 'db', 'username' => null, 'tables' => []];

        [$errors, $warnings] = ConnectionConfig::check('test', $connection);

        $this->assertSame([], $errors);
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('host_fingerprint', $warnings[0]);
        $this->assertStringContainsString('database.username', $warnings[1]);
    }

    public function testCheckDoesNotWarnAboutAnUnusedDatabaseBlock(): void
    {
        $connection = $this->connection();
        $connection['database'] = ['database' => null, 'username' => null, 'password' => null, 'tables' => []];

        [, $warnings] = ConnectionConfig::check('test', $connection);

        $this->assertSame([], $warnings, 'An empty "tables" array is not a configured database (1.x bug)');
    }

    public function testCheckRequiresAKeyOrPassword(): void
    {
        $connection = $this->connection();
        unset($connection['ssh']['key_path']);

        [$errors] = ConnectionConfig::check('test', $connection);

        $this->assertStringContainsString("'ssh.key_path'", $errors[0]);
    }
}
