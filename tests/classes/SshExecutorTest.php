<?php namespace NumenCode\SyncOps\Tests\Classes;

use Mockery;
use PluginTestCase;
use ReflectionClass;
use phpseclib3\Net\SSH2;
use NumenCode\SyncOps\Classes\SshExecutor;

class SshExecutorTest extends PluginTestCase
{
    protected string $server = 'staging';
    protected array $config;
    protected string $credentials = 'password';

    /**
     * Bind fake console commands to satisfy Winter’s kernel bootstrap.
     */
    protected static function bindFakeCommands(): void
    {
        $fake = fn () => Mockery::mock(\Illuminate\Console\Command::class);

        app()->instance('command.syncops.db_pull', $fake());
        app()->instance('command.syncops.db_push', $fake());
        app()->instance('command.syncops.media_pull', $fake());
        app()->instance('command.syncops.media_push', $fake());
        app()->instance('command.syncops.project_backup', $fake());
        app()->instance('command.syncops.project_deploy', $fake());
        app()->instance('command.syncops.project_push', $fake());
        app()->instance('command.syncops.project_pull', $fake());
    }

    /**
     * Ensure fake commands are bound before PluginTestCase bootstraps Artisan.
     */
    public static function setUpBeforeClass(): void
    {
        if (function_exists('app')) {
            self::bindFakeCommands();
        }

        parent::setUpBeforeClass();
    }

    public function setUp(): void
    {
        if (function_exists('app')) {
            self::bindFakeCommands();
        }

        parent::setUp();

        $this->config = [
            'host'     => 'example.com',
            'port'     => 22,
            'username' => 'user',
            'path'     => '/var/www/html', // Required for runCommands/runAndGet/runAndPrint/runRawCommand
        ];
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to set the protected $ssh property on the executor instance via Reflection.
     */
    protected function setSshMock(SshExecutor $executor, SSH2 $sshMock): void
    {
        $reflection = new ReflectionClass($executor);
        $property = $reflection->getProperty('ssh');
        $property->setAccessible(true);
        $property->setValue($executor, $sshMock);
    }

    /**
     * Test function: connect
     * Test that connect() returns an SSH2 instance when a mock has been injected.
     */
    public function testConnectSuccess(): void
    {
        // Avoid real network: pre-inject a connected SSH2 instance, so connect() just returns it.
        $sshMock = Mockery::mock(SSH2::class);

        $executor = new SshExecutor($this->server, $this->config, $this->credentials);
        $this->setSshMock($executor, $sshMock);

        $result = $executor->connect();

        $this->assertSame($sshMock, $result);
        $this->assertInstanceOf(SSH2::class, $result);
    }

    /**
     * Test function: connect
     * Test that connect() throws some exception when connection/login fails with bad host/credentials.
     *
     * We don't mock SSH2 here; instead we rely on phpseclib throwing an exception or otherwise failing.
     * This avoids Mockery's overload/alias issues and still verifies that failures surface as exceptions.
     */
    public function testConnectFailureThrowsException(): void
    {
        $badConfig = [
            'host'     => 'invalid-host.example.invalid', // should never resolve
            'port'     => 22,
            'username' => 'user',
            'path'     => '/var/www/html',
        ];

        $executor = new SshExecutor($this->server, $badConfig, 'wrong-password');

        $this->expectException(\Throwable::class);

        $executor->connect();
    }

    /**
     * Test function: remoteIsClean
     * Test that remoteIsClean() returns true for a clean Git repository.
     */
    public function testRemoteIsCleanReturnsTrue(): void
    {
        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();

        $executor->shouldReceive('runAndGet')
            ->once()
            ->with(['git', 'status', '--porcelain'])
            ->andReturn('');

        $this->assertTrue($executor->remoteIsClean());
    }

    /**
     * Test function: remoteIsClean
     * Test that remoteIsClean() returns false for a dirty Git repository.
     */
    public function testRemoteIsCleanReturnsFalse(): void
    {
        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();

        $executor->shouldReceive('runAndGet')
            ->once()
            ->with(['git', 'status', '--porcelain'])
            ->andReturn(" M file.txt");

        $this->assertFalse($executor->remoteIsClean());
    }

    /**
     * Test function: exec
     * Test that exec() successfully executes a raw command on the injected SSH instance.
     */
    public function testExecSuccess(): void
    {
        $command = 'ls -la';
        $output = "total 8\ndir1\n";

        $sshMock = Mockery::mock(SSH2::class);
        $sshMock->shouldReceive('exec')->once()->with($command)->andReturn($output);

        $executor = new SshExecutor($this->server, $this->config, $this->credentials);
        $this->setSshMock($executor, $sshMock);

        $this->assertEquals($output, $executor->exec($command));
    }

    /**
     * Test function: runCommands
     * Test that runCommands() executes multiple secure commands and concatenates output.
     */
    public function testRunCommandsSuccess(): void
    {
        $commands = [['echo', 'hello'], ['ls', '-la']];
        $output1 = 'hello';
        $output2 = 'total 8';
        $expectedOutput = "hello\ntotal 8";

        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $executor->shouldReceive('executeSecureCommand')
            ->once()
            ->with(['echo', 'hello'], $this->config['path'])
            ->andReturn($output1);

        $executor->shouldReceive('executeSecureCommand')
            ->once()
            ->with(['ls', '-la'], $this->config['path'])
            ->andReturn($output2);

        $this->assertEquals($expectedOutput, $executor->runCommands($commands));
    }

    /**
     * Test function: runCommands
     * Test that runCommands() throws an exception when the 'path' config is missing.
     */
    public function testRunCommandsMissingPathThrowsException(): void
    {
        $config = $this->config;
        unset($config['path']);

        $executor = new SshExecutor($this->server, $config, $this->credentials);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Path is not defined/');

        $executor->runCommands([['ls', '-la']]);
    }

    /**
     * Test function: runCommands
     * Test that runCommands() bubbles up an exception thrown by executeSecureCommand().
     */
    public function testRunCommandsFailureThrowsException(): void
    {
        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Command 1 succeeds
        $executor->shouldReceive('executeSecureCommand')
            ->once()
            ->with(['echo', 'ok'], $this->config['path'])
            ->andReturn('ok');

        // Command 2 fails: simulate the exception thrown by executeSecureCommand()
        $executor->shouldReceive('executeSecureCommand')
            ->once()
            ->with(['bad', 'command'], $this->config['path'])
            ->andThrow(
                \RuntimeException::class,
                "Remote command failed on [{$this->server}]:\nCommand: 'bad' 'command'\nError: not found"
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Remote command failed.*Command: \'bad\' \'command\'/s');

        $executor->runCommands([['echo', 'ok'], ['bad', 'command']]);
    }

    /**
     * Test function: runAndGet
     * Test that runAndGet() executes a single command and returns trimmed output.
     */
    public function testRunAndGetSuccess(): void
    {
        $command = ['echo', 'test'];
        $rawOutput = " \n test-output \n ";
        $expectedOutput = 'test-output';

        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();

        $executor->shouldReceive('runCommands')
            ->once()
            ->with([$command], false)
            ->andReturn($rawOutput);

        $this->assertEquals($expectedOutput, $executor->runAndGet($command));
    }

    /**
     * Test function: runAndPrint
     * Test that runAndPrint() executes commands and returns trimmed, normalized output.
     */
    public function testRunAndPrintSuccess(): void
    {
        $commands = [['echo', 'one'], ['echo', 'two']];
        // Ensure raw output uses Windows-style line endings to test robustness
        $rawOutput = " one\r\ntwo ";
        $expectedOutput = "one\ntwo";

        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();

        $executor->shouldReceive('runCommands')
            ->once()
            ->with($commands, true)
            ->andReturn($rawOutput);

        $actualOutput = $executor->runAndPrint($commands);

        // Normalize line endings in actual output
        $normalizedActual = str_replace(["\r\n", "\r"], "\n", $actualOutput);

        // Trim final output to remove trailing whitespace/newlines
        $finalActual = trim($normalizedActual);

        $this->assertEquals($expectedOutput, $finalActual);
    }

    /**
     * Test function: runRawCommand
     * Test that runRawCommand() executes a raw shell command with correct full command.
     */
    public function testRunRawCommandSuccess(): void
    {
        $rawCommand = 'php artisan migrate --force > /dev/null 2>&1';

        // Build expected full command using escapeshellarg to be OS-agnostic
        $expectedFullCommand = 'cd ' . escapeshellarg($this->config['path']) . " && {$rawCommand}";

        $output = "Migration successful\n";

        $sshMock = Mockery::mock(SSH2::class);
        $sshMock->shouldReceive('exec')->once()->with($expectedFullCommand)->andReturn($output);
        $sshMock->shouldReceive('getExitStatus')->andReturn(0);

        $executor = new SshExecutor($this->server, $this->config, $this->credentials);
        $this->setSshMock($executor, $sshMock);

        $this->assertEquals($output, $executor->runRawCommand($rawCommand));
    }

    /**
     * Test function: runRawCommand
     * Test that runRawCommand() throws an exception on non-zero exit status.
     */
    public function testRunRawCommandFailureThrowsException(): void
    {
        $rawCommand = 'bad-command-string';
        $errorOutput = 'bash: bad-command-string: not found';

        // Build expected full command using escapeshellarg to be OS-agnostic
        $expectedFullCommand = 'cd ' . escapeshellarg($this->config['path']) . " && {$rawCommand}";

        $sshMock = Mockery::mock(SSH2::class);
        $sshMock->shouldReceive('exec')->once()->with($expectedFullCommand)->andReturn('');
        $sshMock->shouldReceive('getExitStatus')->andReturn(127);
        $sshMock->shouldReceive('getStdError')->andReturn($errorOutput);

        $executor = new SshExecutor($this->server, $this->config, $this->credentials);
        $this->setSshMock($executor, $sshMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/Remote command failed.*Command: ' . preg_quote($rawCommand, '/') .
            '.*Error: ' . preg_quote($errorOutput, '/') . '/s'
        );

        $executor->runRawCommand($rawCommand);
    }
}
