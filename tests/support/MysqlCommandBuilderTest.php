<?php namespace NumenCode\SyncOps\Tests\Support;

use PluginTestCase;
use NumenCode\SyncOps\Support\MysqlCommandBuilder;

class MysqlCommandBuilderTest extends PluginTestCase
{
    protected string $expectedUser;
    protected string $expectedPass;
    protected string $expectedDb;
    protected string $expectedUsersTable;
    protected string $expectedOrdersTable;
    protected string $expectedDbTest;
    protected string $expectedGzipOutput;
    protected string $expectedPlainOutput;

    public function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->expectedUser = '-u"testuser"';
            $this->expectedPass = "-p'secret'";
            $this->expectedDb = '"mydb"';
            $this->expectedUsersTable = '"users"';
            $this->expectedOrdersTable = '"orders"';
            $this->expectedDbTest = '"mydbtest"';
            $this->expectedGzipOutput = '| gzip > "/backups/db.sql.gz"';
            $this->expectedPlainOutput = '> "/backups/db.sql"';
        } else {
            $this->expectedUser = "-u'testuser'";
            $this->expectedPass = "-p'secret'";
            $this->expectedDb = "'mydb'";
            $this->expectedUsersTable = "'users'";
            $this->expectedOrdersTable = "'orders'";
            $this->expectedDbTest = "'mydbtest'";
            $this->expectedGzipOutput = '| gzip > \'/backups/db.sql.gz\'';
            $this->expectedPlainOutput = '> \'/backups/db.sql\'';
        }
    }

    public function testDumpWithGzip(): void
    {
        $config = ['username' => 'testuser', 'password' => 'secret', 'database' => 'mydb'];
        $command = MysqlCommandBuilder::dump($config, '/backups/db.sql.gz', true);

        $this->assertStringContainsString(
            "mysqldump --skip-comments --replace {$this->expectedUser} {$this->expectedPass} {$this->expectedDb}",
            $command
        );
        $this->assertStringContainsString($this->expectedGzipOutput, $command);
    }

    public function testDumpWithoutGzip(): void
    {
        $config = ['username' => 'testuser', 'password' => 'secret', 'database' => 'mydb'];
        $command = MysqlCommandBuilder::dump($config, '/backups/db.sql', false);

        $this->assertStringContainsString(
            "mysqldump --skip-comments --replace {$this->expectedUser} {$this->expectedPass} {$this->expectedDb}",
            $command
        );
        $this->assertStringContainsString($this->expectedPlainOutput, $command);
    }

    public function testDumpWithSpecificTables(): void
    {
        $config = ['username' => 'testuser', 'password' => 'secret', 'database' => 'mydb'];
        $tables = ['users', 'orders'];
        $command = MysqlCommandBuilder::dump($config, '/backups/tables.sql', true, $tables);

        $this->assertStringContainsString($this->expectedUsersTable, $command);
        $this->assertStringContainsString($this->expectedOrdersTable, $command);

        // Output path for gzip depends on platform
        $expectedGzip = PHP_OS_FAMILY === 'Windows'
            ? '| gzip > "/backups/tables.sql"'
            : '| gzip > \'/backups/tables.sql\'';

        $this->assertStringContainsString($expectedGzip, $command);
    }

    public function testImportCommand(): void
    {
        $dbConfig = ['username' => 'testuser', 'password' => 'secret', 'database' => 'mydb'];
        $command = MysqlCommandBuilder::import($dbConfig, 'C:\\backups\\db.sql.gz');

        $this->assertStringContainsString("-utestuser", $command);
        $this->assertStringContainsString("-psecret", $command);
        $this->assertStringContainsString($this->expectedDbTest, $command);

        // Path should always be normalized to forward slashes
        $this->assertStringContainsString("C:/backups/db.sql.gz", $command);
    }
}
