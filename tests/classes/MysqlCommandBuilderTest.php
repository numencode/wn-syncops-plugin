<?php namespace NumenCode\SyncOps\Tests\Classes;

use PluginTestCase;
use NumenCode\SyncOps\Classes\MysqlCommandBuilder;

class MysqlCommandBuilderTest extends PluginTestCase
{
    protected string $expectedUser;
    protected string $expectedPass;
    protected string $expectedDb;
    protected string $expectedDbTest;
    protected string $expectedUsersTable;
    protected string $expectedOrdersTable;
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

    /**
     * Test function: dump
     * Test that command is built correctly when gzip compression is enabled.
     */
    public function testDumpWithGzip(): void
    {
        $config = ['username' => 'testuser', 'password' => 'secret', 'database' => 'mydb'];
        $command = MysqlCommandBuilder::dump($config, '/backups/db.sql.gz', true);

        $this->assertStringContainsString(
            "mysqldump --skip-comments --replace {$this->expectedUser} {$this->expectedPass} {$this->expectedDb}",
            $command,
            'mysqldump command should include user, pass, and database name'
        );
        $this->assertStringContainsString(
            $this->expectedGzipOutput,
            $command,
            'Command should pipe output through gzip'
        );
    }

    /**
     * Test function: dump
     * Test that command is built correctly when gzip compression is disabled.
     */
    public function testDumpWithoutGzip(): void
    {
        $config = ['username' => 'testuser', 'password' => 'secret', 'database' => 'mydb'];
        $command = MysqlCommandBuilder::dump($config, '/backups/db.sql', false);

        $this->assertStringContainsString(
            "mysqldump --skip-comments --replace {$this->expectedUser} {$this->expectedPass} {$this->expectedDb}",
            $command,
            'mysqldump command should include user, pass, and database name'
        );
        $this->assertStringContainsString(
            $this->expectedPlainOutput,
            $command,
            'Command should redirect to plain file output'
        );
    }

    /**
     * Test function: dump
     * Test that command includes specific tables and gzip output path is correct per platform.
     */
    public function testDumpWithSpecificTables(): void
    {
        $config = ['username' => 'testuser', 'password' => 'secret', 'database' => 'mydb'];
        $tables = ['users', 'orders'];
        $command = MysqlCommandBuilder::dump($config, '/backups/tables.sql.gz', true, $tables);

        $this->assertStringContainsString($this->expectedUsersTable, $command, 'Command should include users table');
        $this->assertStringContainsString($this->expectedOrdersTable, $command, 'Command should include orders table');

        $expectedGzip = PHP_OS_FAMILY === 'Windows'
            ? '| gzip > "/backups/tables.sql.gz"'
            : '| gzip > \'/backups/tables.sql.gz\'';

        $this->assertStringContainsString($expectedGzip, $command, 'Output path for gzip should be correct');
    }

    /**
     * Test function: dump
     * Test that command does not include any empty quotes or extra spaces when no tables are provided.
     */
    public function testDumpWithNoTablesDoesNotAddExtraSpaces(): void
    {
        $config = ['username' => 'user', 'password' => 'pass', 'database' => 'db'];
        $command = MysqlCommandBuilder::dump($config, '/dump.sql', false, []);

        $this->assertStringNotContainsString("''", $command, 'Command should not contain empty quotes');
        $this->assertStringNotContainsString('  ', $command, 'Command should not contain double spaces');
    }

    /**
     * Test function: dump
     * Test that passwords containing single quotes are escaped correctly in the command.
     */
    public function testDumpEscapesSingleQuoteInPassword(): void
    {
        $config = ['username' => 'me', 'password' => "o'connor", 'database' => 'db'];
        $command = MysqlCommandBuilder::dump($config, '/dump.sql', false);

        $this->assertStringContainsString(
            "-p'o'\\''connor'",
            $command,
            'Password containing single quote should be safely escaped'
        );
    }

    /**
     * Test function: import
     * Test that import command is built correctly and file path is normalized.
     */
    public function testImportCommand(): void
    {
        $dbConfig = ['username' => 'testuser', 'password' => 'secret', 'database' => 'mydb'];
        $command = MysqlCommandBuilder::import($dbConfig, 'C:\\backups\\db.sql.gz');

        $this->assertStringContainsString('-utestuser', $command, 'Username should appear in command');
        $this->assertStringContainsString('-psecret', $command, 'Password should appear in command');
        $this->assertStringContainsString($this->expectedDbTest, $command, 'Database name should include test suffix');
        $this->assertStringContainsString(
            str_replace('\\', '/', 'C:\\backups\\db.sql.gz'),
            $command,
            'File path should be normalized to forward slashes'
        );
    }
}
