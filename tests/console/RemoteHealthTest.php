<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use PluginTestCase;
use NumenCode\SyncOps\Console\RemoteHealth;
use NumenCode\SyncOps\Classes\RemoteExecutor;
use NumenCode\SyncOps\Classes\SshExecutor;

/**
 * Stub executor to bypass the real RemoteExecutor constructor.
 */
class RemoteExecutorStubForRemoteHealth extends RemoteExecutor
{
    public function __construct()
    {
        // Intentionally bypass parent::__construct()
    }
}

class RemoteHealthTest extends PluginTestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper: create a RemoteExecutor stub with injected SshExecutor mock.
     */
    protected function makeExecutor(array $config, callable $sshSetup): RemoteExecutor
    {
        $executor = new RemoteExecutorStubForRemoteHealth();
        $executor->config = $config;

        $ssh = Mockery::mock(SshExecutor::class);
        $sshSetup($ssh);
        $executor->ssh = $ssh;

        return $executor;
    }

    /**
     * Test function: handle
     * When --full is not used and a valid configuration is present, the command should:
     *  - perform system checks (uptime, df -h),
     *  - perform basic PHP checks (version only),
     *  - detect the database client version (MariaDB/MySQL) without connectivity tests,
     *  - perform project checks (pwd, Git status, Laravel + Winter versions),
     *  - and return SUCCESS.
     */
    public function testHandleRunsBasicChecksWithoutFullAndReturnsSuccess(): void
    {
        $config = [
            'database' => [
                'database' => 'appdb',
                'username' => 'dbuser',
                'password' => 'secret',
            ],
        ];

        $executor = $this->makeExecutor($config, function ($ssh) {
            // System
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['uptime'])
                ->andReturn(' 10:10:10 up 1 day,  1 user,  load average: 0.00, 0.01, 0.05');

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['df', '-h'])
                ->andReturn("Filesystem      Size  Used Avail Use% Mounted on\n/dev/sda1        50G   10G   37G  22% /");

            // PHP
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['php', '-v'])
                ->andReturn("PHP 8.2.0 (cli) (built: Jan  1 2024)\nAdditional stuff...");

            // Database client detection: MariaDB missing, fall back to MySQL
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['mariadb', '--version'])
                ->andThrow(new \RuntimeException('mariadb: command not found'));

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['mysql', '--version'])
                ->andReturn("mysql  Ver 8.0.30 for Linux on x86_64 (MySQL Community Server)");

            // Project checks
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['pwd'])
                ->andReturn('/var/www/app');

            $ssh->shouldReceive('remoteIsClean')
                ->once()
                ->andReturn(true);

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])
                ->andReturn('main');

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['php', 'artisan', '--version'])
                ->andReturn('Laravel Framework 9.52.21 - Winter CMS');

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['php', 'artisan', 'winter:version'])
                ->andReturn(
                    "*** Detecting Winter CMS build...\n" .
                    "*** Detected Winter CMS build 1.2.9.\n"
                );
        });

        $cmd = Mockery::mock(RemoteHealth::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('server')->andReturn('production');
        $cmd->shouldReceive('option')->with('full')->andReturn(false);

        $cmd->shouldReceive('createExecutor')
            ->once()
            ->with('production')
            ->andReturn($executor);

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('warn')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')->zeroOrMoreTimes();

        $cmd->shouldReceive('info')
            ->once()
            ->with(Mockery::pattern('/Remote health check completed/'));

        $result = $cmd->handle();

        $this->assertSame(RemoteHealth::SUCCESS, $result);
    }

    /**
     * Test function: checkDatabase
     * When the syncops connection has no database configuration (or no database name),
     * checkDatabase() should print a notice and skip all version/connectivity checks.
     */
    public function testCheckDatabaseSkipsWhenNoDatabaseConfig(): void
    {
        $config = [
            'database' => [], // no "database" key => treated as missing
        ];

        $executor = $this->makeExecutor($config, function ($ssh) {
            // No DB-related SSH calls should be made in this scenario.
            $ssh->shouldReceive('runAndGet')->never();
            $ssh->shouldReceive('runRawCommand')->never();
        });

        $cmd = Mockery::mock(RemoteHealth::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('line')
            ->once()
            ->with('Database checks:');

        $cmd->shouldReceive('comment')
            ->once()
            ->with(Mockery::pattern('/No database configuration found/i'));

        // Exercise protected checkDatabase() via a bound closure.
        (function () use ($executor) {
            $this->checkDatabase($executor, false);
        })->call($cmd);

        // Prevent "risky test" warning.
        $this->assertTrue(true);
    }

    /**
     * Test function: handle
     * When --full is used and a database configuration with credentials is present,
     * the command should:
     *  - run extended PHP checks (php -m),
     *  - detect the MariaDB client via "mariadb --version",
     *  - attempt a "SELECT 1" database connectivity check via runRawCommand(),
     *  *  perform project checks,
     *  - and return SUCCESS.
     */
    public function testHandleWithFullFlagRunsExtendedPhpAndDbConnectivityChecks(): void
    {
        $config = [
            'database' => [
                'database' => 'db',
                'username' => 'dbuser',
                'password' => 'secret',
            ],
        ];

        $executor = $this->makeExecutor($config, function ($ssh) {
            // System
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['uptime'])
                ->andReturn('up 2 days');

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['df', '-h'])
                ->andReturn('Filesystem table');

            // PHP version + modules
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['php', '-v'])
                ->andReturn('PHP 8.2.1 (cli)');

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['php', '-m'])
                ->andReturn("pdo\nmbstring\nopenssl");

            // Database client detection – MariaDB available
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['mariadb', '--version'])
                ->andReturn('mariadb from 11.4.7-MariaDB');

            // Connectivity: expect a SELECT 1 probe using mariadb client.
            // We don't over-constrain the command; just ensure key bits are present.
            $ssh->shouldReceive('runRawCommand')
                ->once()
                ->with(Mockery::on(function (string $cmd) {
                    return is_string($cmd)
                        && str_contains($cmd, 'MYSQL_PWD=')
                        && str_contains($cmd, 'mariadb')
                        && str_contains($cmd, 'dbuser')
                        && str_contains($cmd, 'SELECT 1')
                        && str_contains($cmd, 'db');
                }))
                ->andReturn('');

            // Project checks
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['pwd'])
                ->andReturn('/var/www/app');

            $ssh->shouldReceive('remoteIsClean')
                ->once()
                ->andReturn(true);

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])
                ->andReturn('main');

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['php', 'artisan', '--version'])
                ->andReturn('Laravel Framework 9.52.21 - Winter CMS');

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['php', 'artisan', 'winter:version'])
                ->andReturn('*** Detected Winter CMS build 1.2.9.');
        });

        $cmd = Mockery::mock(RemoteHealth::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('server')->andReturn('prod');
        $cmd->shouldReceive('option')->with('full')->andReturn(true);

        $cmd->shouldReceive('createExecutor')
            ->once()
            ->with('prod')
            ->andReturn($executor);

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('warn')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')->zeroOrMoreTimes();

        $cmd->shouldReceive('info')
            ->once()
            ->with(Mockery::pattern('/Remote health check completed/'));

        $result = $cmd->handle();

        $this->assertSame(RemoteHealth::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * If createExecutor() throws an exception (e.g. connection failure), the command
     * should catch it, print an error message, and return FAILURE.
     */
    public function testHandleReturnsFailureWhenExecutorCreationFails(): void
    {
        $cmd = Mockery::mock(RemoteHealth::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('server')->andReturn('broken');
        $cmd->shouldReceive('option')->with('full')->andReturn(false);

        $cmd->shouldReceive('createExecutor')
            ->once()
            ->with('broken')
            ->andThrow(new \RuntimeException('connection failed'));

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('error')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(RemoteHealth::FAILURE, $result);
    }

    /**
     * Test function: detectDatabaseClient
     * When the "mariadb" client is available, detectDatabaseClient() should
     * prefer it and return "mariadb" along with the raw version output.
     */
    public function testDetectDatabaseClientPrefersMariaDbWhenAvailable(): void
    {
        $executor = $this->makeExecutor([], function ($ssh) {
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['mariadb', '--version'])
                ->andReturn('mariadb from 11.4.7-MariaDB');
        });

        $cmd = Mockery::mock(RemoteHealth::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $result = (function () use ($executor) {
            return $this->detectDatabaseClient($executor);
        })->call($cmd);

        $this->assertSame('mariadb', $result[0]);
        $this->assertStringContainsString('mariadb', strtolower($result[1]));
    }

    /**
     * Test function: detectDatabaseClient
     * When the "mariadb" client is not available, detectDatabaseClient() should
     * fall back to "mysql" and return its version output instead.
     */
    public function testDetectDatabaseClientFallsBackToMysqlWhenMariaDbMissing(): void
    {
        $executor = $this->makeExecutor([], function ($ssh) {
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['mariadb', '--version'])
                ->andThrow(new \RuntimeException('mariadb: command not found'));

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['mysql', '--version'])
                ->andReturn('mysql  Ver 8.0.30 for Linux on x86_64');
        });

        $cmd = Mockery::mock(RemoteHealth::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $result = (function () use ($executor) {
            return $this->detectDatabaseClient($executor);
        })->call($cmd);

        $this->assertSame('mysql', $result[0]);
        $this->assertStringContainsString('mysql', strtolower($result[1]));
    }

    /**
     * Test function: firstLine
     * Verifies that firstLine() returns only the first line of a multi-line string
     * and handles single-line and empty strings correctly.
     */
    public function testFirstLineHelperReturnsFirstLine(): void
    {
        $cmd = Mockery::mock(RemoteHealth::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $multiLine = "Line one\nLine two\nLine three";
        $singleLine = "Only one line";
        $empty = '';

        $firstMulti = (function () use ($multiLine) {
            return $this->firstLine($multiLine);
        })->call($cmd);

        $firstSingle = (function () use ($singleLine) {
            return $this->firstLine($singleLine);
        })->call($cmd);

        $firstEmpty = (function () use ($empty) {
            return $this->firstLine($empty);
        })->call($cmd);

        $this->assertSame('Line one', $firstMulti);
        $this->assertSame('Only one line', $firstSingle);
        $this->assertSame('', $firstEmpty);
    }

    /**
     * Test function: checkProject
     * Ensures that the Winter CMS version output from "php artisan winter:version"
     * is parsed and printed in a clean single-line format:
     *   "Winter CMS: Detected Winter CMS build X.Y.Z."
     * instead of the original multi-line output with asterisks.
     */
    public function testCheckProjectFormatsWinterVersionNicely(): void
    {
        $executor = $this->makeExecutor([], function ($ssh) {
            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['pwd'])
                ->andReturn('/var/www/app');

            $ssh->shouldReceive('remoteIsClean')
                ->once()
                ->andReturn(true);

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])
                ->andReturn('prod');

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['php', 'artisan', '--version'])
                ->andReturn('Laravel Framework 9.52.21 - Winter CMS');

            $ssh->shouldReceive('runAndGet')
                ->once()
                ->with(['php', 'artisan', 'winter:version'])
                ->andReturn(
                    "*** Detecting Winter CMS build...\n" .
                    "*** Detected Winter CMS build 1.2.9.\n"
                );
        });

        $cmd = Mockery::mock(RemoteHealth::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('line')
            ->once()
            ->with('Project checks:');

        $cmd->shouldReceive('comment')
            ->once()
            ->with('  Working directory (pwd): /var/www/app');

        $cmd->shouldReceive('info')
            ->once()
            ->with("  Git: working tree is clean on branch 'prod'.");

        $cmd->shouldReceive('comment')
            ->once()
            ->with('  Framework: Laravel Framework 9.52.21 - Winter CMS');

        $cmd->shouldReceive('comment')
            ->once()
            ->with('  Winter CMS: Detected Winter CMS build 1.2.9.');

        $cmd->shouldReceive('warn')->zeroOrMoreTimes();

        // Exercise protected checkProject() via a bound closure.
        (function () use ($executor) {
            $this->checkProject($executor);
        })->call($cmd);

        // Prevent "risky test" warning.
        $this->assertTrue(true);
    }
}
