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

        // Create mock for SshExecutor
        $this->sshMock = Mockery::mock(SshExecutor::class);

        // Inject mock into helper
        $this->runner->setSshExecutor($this->sshMock);
    }

    /**
     * Test function: runRemote
     * Test running a remote command using runAndGet.
     */
    public function testRunRemote(): void
    {
        $this->sshMock->shouldReceive('runAndGet')
            ->once()
            ->with(['ls', '-la'])
            ->andReturn("mocked output");

        $output = $this->runner->runRemote('my-server', ['ls', '-la']);

        // Assert mocked output is returned
        $this->assertEquals("mocked output", $output);
    }

    /**
     * Test function: runRemoteAndPrint
     * Test running remote commands using runAndPrint.
     */
    public function testRunRemoteAndPrint(): void
    {
        $this->sshMock->shouldReceive('runAndPrint')
            ->once()
            ->with(['echo', 'hello'])
            ->andReturn("printed hello");

        $output = $this->runner->runRemoteAndPrint('my-server', ['echo', 'hello']);

        // Assert mocked output is returned
        $this->assertEquals("printed hello", $output);
    }

    /**
     * Test function: runRemoteRaw
     * Test running a raw remote command using runRawCommand.
     */
    public function testRunRemoteRaw(): void
    {
        $this->sshMock->shouldReceive('runRawCommand')
            ->once()
            ->with('uptime')
            ->andReturn("raw uptime");

        $output = $this->runner->runRemoteRaw('my-server', 'uptime');

        // Assert mocked output is returned
        $this->assertEquals("raw uptime", $output);
    }

    /**
     * Test function: ssh
     * Test that ssh() caches the executor instance once created or injected.
     */
    public function testSshCaching(): void
    {
        // Inject our mock once
        $this->runner->setSshExecutor($this->sshMock);

        // First call should return the injected mock
        $first = $this->runner->ssh('server1');

        // Second call with a different server should still return the same mock
        $second = $this->runner->ssh('server2');

        $this->assertSame($first, $second);
    }

    /**
     * Test function: ssh
     * Test that manually overwriting sshExecutor replaces the cached instance.
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
 * Helper class to expose protected methods of RunsRemoteCommands.
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
