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

    public function setUp(): void
    {
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
     * Test that connect() returns an SSH2 instance on successful connection.
     */
    public function testConnectSuccess(): void
    {
        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        try {
            $result = $executor->connect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Real SSH connection not available in test environment or login failed.');
            return;
        }

        $this->assertInstanceOf(SSH2::class, $result);
    }

    /**
     * Test function: connect
     * Test that connect() throws an exception when connection fails.
     */
    public function testConnectFailureThrowsException(): void
    {
        $executor = new SshExecutor($this->server, [
            'host'     => 'nonexistent-host.invalid',
            'port'     => 22,
            'username' => 'user',
        ], 'wrong-password');

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/(SSH|login|failed)/i');

        $executor->connect();
    }

    /**
     * Test function: remoteIsClean
     * Test that remoteIsClean() returns true for a clean Git repository.
     */
    public function testRemoteIsCleanReturnsTrue(): void
    {
        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();

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
        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();

        $executor->shouldReceive('runAndGet')
            ->once()
            ->with(['git', 'status', '--porcelain'])
            ->andReturn(" M file.txt");

        $this->assertFalse($executor->remoteIsClean());
    }

    /**
     * Test function: exec
     * Test that exec() successfully executes a raw command.
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
     * Test that runCommands() throws an exception on remote command failure.
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

        // Command 2 fails: simulate the exception that executeSecureCommand would throw
        $executor->shouldReceive('executeSecureCommand')
            ->once()
            ->with(['bad', 'command'], $this->config['path'])
            ->andThrow(\RuntimeException::class, "Remote command failed on [{$this->server}]:\nCommand: 'bad' 'command'\nError: not found");

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

        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();

        $executor->shouldReceive('runCommands')
            ->once()
            ->with([$command], false)
            ->andReturn($rawOutput);

        $this->assertEquals($expectedOutput, $executor->runAndGet($command));
    }

    /**
     * Test function: runAndPrint
     * Test that runAndPrint() executes commands and returns trimmed output.
     */
    public function testRunAndPrintSuccess(): void
    {
        $commands = [['echo', 'one'], ['echo', 'two']];
        $rawOutput = " one\ntwo ";
        $expectedOutput = 'one
two';

        $executor = Mockery::mock(SshExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();

        $executor->shouldReceive('runCommands')
            ->once()
            ->with($commands, true)
            ->andReturn($rawOutput);

        $this->assertEquals($expectedOutput, $executor->runAndPrint($commands));
    }

    /**
     * Test function: runRawCommand
     * Test that runRawCommand() executes a raw shell command with correct full command.
     */
    public function testRunRawCommandSuccess(): void
    {
        $rawCommand = 'php artisan migrate --force > /dev/null 2>&1';

        // Use double quotes around the path to match the executor's actual command construction
        $expectedFullCommand = "cd \"{$this->config['path']}\" && {$rawCommand}";

        $output = "Migration successful\n";

        $sshMock = Mockery::mock(SSH2::class);
        $sshMock->shouldReceive('exec')->once()->with($expectedFullCommand)->andReturn($output);
        $sshMock->shouldReceive('getExitStatus')->andReturn(0);

        $executor = new SshExecutor($this->server, $this->config, $this->credentials);
        $this->setSshMock($executor, $sshMock); // Inject the mock via Reflection

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

        // Use double quotes for the path here as well
        $expectedFullCommand = "cd \"{$this->config['path']}\" && {$rawCommand}";

        $sshMock = Mockery::mock(SSH2::class);
        $sshMock->shouldReceive('exec')->once()->with($expectedFullCommand)->andReturn('');
        $sshMock->shouldReceive('getExitStatus')->andReturn(127);
        $sshMock->shouldReceive('getStdError')->andReturn($errorOutput);

        $executor = new SshExecutor($this->server, $this->config, $this->credentials);
        $this->setSshMock($executor, $sshMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Remote command failed.*Command: ' . preg_quote($rawCommand, '/') . '.*Error: ' . preg_quote($errorOutput, '/') . '/s');

        $executor->runRawCommand($rawCommand);
    }
}
