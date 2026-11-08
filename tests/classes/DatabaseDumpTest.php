<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Tests\Classes;

use NumenCode\SyncOps\Classes\CommandResult;
use NumenCode\SyncOps\Classes\DatabaseDump;
use NumenCode\SyncOps\Tests\SyncOpsTestCase;
use RuntimeException;

class DatabaseDumpTest extends SyncOpsTestCase
{
    public function testOptionFileQuotesValues(): void
    {
        $file = DatabaseDump::optionFile([
            'username'    => 'user',
            'password'    => 'pa"ss\\word',
            'host'        => '127.0.0.1',
            'port'        => 3306,
            'unix_socket' => '',
        ]);

        $this->assertSame("[client]\nuser=\"user\"\npassword=\"pa\\\"ss\\\\word\"\nhost=\"127.0.0.1\"\nport=\"3306\"\n", $file);
    }

    public function testRemoteDumpKeepsThePasswordOutOfTheCommand(): void
    {
        $this->server->results['/command -v mariadb-dump/'] = new CommandResult(0, "/usr/bin/mariadb-dump\n");

        $path = DatabaseDump::dumpRemote($this->server, '/tmp/syncops.test', ['users', 'posts']);

        $this->assertSame('/tmp/syncops.test/dump.sql.gz', $path);
        $this->assertStringContainsString('password="Secret\"Pa$$&word"', $this->server->written['/tmp/syncops.test/client.cnf']);

        $dump = $this->server->commands[array_key_last($this->server->commands)];
        $this->assertStringStartsWith('set -o pipefail; ', $dump);
        $this->assertStringContainsString("'/usr/bin/mariadb-dump' '--defaults-extra-file=/tmp/syncops.test/client.cnf'", $dump);
        $this->assertStringContainsString("'--single-transaction' '--quick' '--no-tablespaces' '--skip-comments' 'remote_db' 'users' 'posts'", $dump);
        $this->assertStringEndsWith("| gzip > '/tmp/syncops.test/dump.sql.gz'", $dump);

        foreach ($this->server->commands as $command) {
            $this->assertStringNotContainsString('Secret', $command, 'No command contains the password');
        }
    }

    public function testRemoteDumpFailureNamesTheStep(): void
    {
        $this->server->results['/command -v/'] = new CommandResult(0, "/usr/bin/mysqldump\n");
        $this->server->results['/pipefail/'] = new CommandResult(2, '', 'Access denied');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Dumping the database 'remote_db' on the server failed (exit code 2):\nAccess denied");

        DatabaseDump::dumpRemote($this->server, '/tmp/syncops.test');
    }

    public function testGunzipDropsTheMariaDbSandboxLine(): void
    {
        $dir = storage_path('temp/syncops-tests');
        @mkdir($dir, 0777, true);
        $body = str_repeat("INSERT INTO t VALUES (1);\n", 50000);

        file_put_contents($dir . '/a.gz', gzencode("/*M!999999\\- enable the sandbox mode */\n" . $body));
        DatabaseDump::gunzip($dir . '/a.gz', $dir . '/a.sql');
        $this->assertSame($body, file_get_contents($dir . '/a.sql'));

        file_put_contents($dir . '/b.gz', gzencode("-- MySQL dump\n" . $body));
        DatabaseDump::gunzip($dir . '/b.gz', $dir . '/b.sql');
        $this->assertSame("-- MySQL dump\n" . $body, file_get_contents($dir . '/b.sql'), 'Other first lines are kept');
    }

    public function testOnlyMysqlAndMariaDbConnectionsAreSupported(): void
    {
        config(['database.connections.lite' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("uses 'sqlite'; SyncOps supports MySQL and MariaDB");

        DatabaseDump::localConnection('lite');
    }

    public function testLocalBinariesComeFromTheConfig(): void
    {
        config(['syncops.local.mysql' => null]);

        $this->assertSame('mysql', DatabaseDump::binary('mysql'), 'Default: from the PATH');

        config(['syncops.local.mysqldump' => 'C:/mariadb/bin/mysqldump.exe']);

        $this->assertSame('C:/mariadb/bin/mysqldump.exe', DatabaseDump::binary('mysqldump'));
    }
}
