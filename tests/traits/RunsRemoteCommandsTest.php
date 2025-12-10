<?php namespace NumenCode\SyncOps\Tests\Traits;

use Mockery;
use PluginTestCase;
use NumenCode\SyncOps\Classes\SshExecutor;
use NumenCode\SyncOps\Traits\RunsRemoteCommands;

class RunsRemoteCommandsTest extends PluginTestCase
{
    protected RunsRemoteCommandsTestHelper $runner;
    protected $sshMock;

    public function setUp(): void
    {
        parent::setUp();

        $this->runner = new RunsRemoteCommandsTestHelper();

        // Create mock for SshExecutor and inject it into the helper
        $this->sshMock = Mockery::mock(SshExecutor::class);
        $this->runner->setSshExecutor($this->sshMock);
    }

    /**
     * Test function: runRemote
     * It should delegate to SshExecutor::runAndGet() and return its output.
     */
    public function testRunRemote(): void
    {
        $this->sshMock->shouldReceive('runAndGet')
            ->once()
            ->with(['ls', '-la'])
            ->andReturn('mocked output');

        $output = $this->runner->runRemote('my-server', ['ls', '-la']);

        $this->assertSame('mocked output', $output);
    }

    /**
     * Test function: runRemoteAndPrint
     * It should delegate to SshExecutor::runAndPrint() and return its output.
     */
    public function testRunRemoteAndPrint(): void
    {
        $this->sshMock->shouldReceive('runAndPrint')
            ->once()
            ->with(['echo', 'hello'])
            ->andReturn('printed hello');

        $output = $this->runner->runRemoteAndPrint('my-server', ['echo', 'hello']);

        $this->assertSame('printed hello', $output);
    }

    /**
     * Test function: runRemoteRaw
     * It should delegate to SshExecutor::runRawCommand() and return its output.
     */
    public function testRunRemoteRaw(): void
    {
        $this->sshMock->shouldReceive('runRawCommand')
            ->once()
            ->with('uptime')
            ->andReturn('raw uptime');

        $output = $this->runner->runRemoteRaw('my-server', 'uptime');

        $this->assertSame('raw uptime', $output);
    }

    /**
     * Test function: runRemote
     * It should reuse the same cached SshExecutor instance across multiple calls,
     * even if the server name argument changes.
     */
    public function testRunRemoteUsesCachedSshExecutorAcrossCalls(): void
    {
        $this->sshMock->shouldReceive('runAndGet')->once()->with(['ls'])->andReturn('first');
        $this->sshMock->shouldReceive('runAndGet')->once()->with(['pwd'])->andReturn('second');

        $firstOutput = $this->runner->runRemote('serverX', ['ls']);
        $secondOutput = $this->runner->runRemote('serverY', ['pwd']);

        $this->assertSame('first', $firstOutput);
        $this->assertSame('second', $secondOutput);
    }

    /**
     * Test function: ssh
     * It should return the same cached SshExecutor for subsequent calls,
     * regardless of the server name passed in.
     */
    public function testSshCaching(): void
    {
        $first = $this->runner->ssh('server1');
        $second = $this->runner->ssh('server2');

        $this->assertSame($first, $second);
    }

    /**
     * Test function: ssh
     * It should allow manually overwriting the cached SshExecutor instance
     * by setting the sshExecutor property via the helper.
     */
    public function testSshExecutorCanBeOverwritten(): void
    {
        $this->runner->setSshExecutor($this->sshMock);
        $this->assertSame($this->sshMock, $this->runner->ssh('server1'));

        $newMock = Mockery::mock(SshExecutor::class);
        $this->runner->setSshExecutor($newMock);

        $this->assertSame($newMock, $this->runner->ssh('server2'));
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

/**
 * Helper class to expose protected methods of RunsRemoteCommands for testing.
 */
class RunsRemoteCommandsTestHelper
{
    use RunsRemoteCommands {
        ssh as public;
        runRemote as public;
        runRemoteAndPrint as public;
        runRemoteRaw as public;
    }

    public function setSshExecutor($executor): void
    {
        $this->sshExecutor = $executor;
    }
}
