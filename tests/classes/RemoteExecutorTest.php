<?php namespace NumenCode\SyncOps\Tests\Classes;

use Mockery;
use PluginTestCase;
use NumenCode\SyncOps\Classes\SshExecutor;
use NumenCode\SyncOps\Classes\SftpExecutor;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class RemoteExecutorTest extends PluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: __construct
     * Test that constructor throws an exception when server config is empty.
     */
    public function testConstructorThrowsIfNoConfig(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No config for server invalid_server");

        // Provide empty array to satisfy typed property
        config()->set("syncops.connections.invalid_server", []);

        new RemoteExecutor('invalid_server');
    }

    /**
     * Test function: __construct
     * Test that SSH and SFTP executors are created with password credentials.
     */
    public function testConstructorWithPassword(): void
    {
        $server = 'server1';
        $password = 'secret';

        config()->set("syncops.connections.$server", [
            'host' => 'example.com',
            'username' => 'user',
            'password' => $password,
            'key_path' => '',
        ]);

        // Mock executors to avoid real connections
        $sshMock = Mockery::mock(SshExecutor::class)->makePartial();
        $sftpMock = Mockery::mock(SftpExecutor::class)->makePartial();

        $this->app->bind(SshExecutor::class, fn() => $sshMock);
        $this->app->bind(SftpExecutor::class, fn() => $sftpMock);

        $executor = new RemoteExecutor($server);

        $this->assertInstanceOf(SshExecutor::class, $executor->ssh);
        $this->assertInstanceOf(SftpExecutor::class, $executor->sftp);
    }

    /**
     * Test function: __construct
     * Test that SSH and SFTP executors are created with public key credentials.
     */
    public function testConstructorWithPublicKey(): void
    {
        $server = 'server2';
        $keyPath = __DIR__ . '/mock_key.pem';
        file_put_contents($keyPath, 'FAKE_KEY');

        config()->set("syncops.connections.$server", [
            'host' => 'example.com',
            'username' => 'user',
            'password' => '',
            'key_path' => $keyPath,
        ]);

        // Mock PublicKeyLoader
        $mockKeyLoader = Mockery::mock('alias:phpseclib3\Crypt\PublicKeyLoader');
        $mockKeyLoader->shouldReceive('load')->once()->andReturn('PUBLIC_KEY');

        $sshMock = Mockery::mock(SshExecutor::class)->makePartial();
        $sftpMock = Mockery::mock(SftpExecutor::class)->makePartial();

        $this->app->bind(SshExecutor::class, fn() => $sshMock);
        $this->app->bind(SftpExecutor::class, fn() => $sftpMock);

        $executor = new RemoteExecutor($server);

        $this->assertInstanceOf(SshExecutor::class, $executor->ssh);
        $this->assertInstanceOf(SftpExecutor::class, $executor->sftp);

        unlink($keyPath);
    }

    /**
     * Test function: connectBoth
     * Test that connectBoth() calls connect() on both SSH and SFTP executors.
     */
    public function testConnectBoth(): void
    {
        $sshMock = Mockery::mock(SshExecutor::class);
        $sftpMock = Mockery::mock(SftpExecutor::class);

        $sshMock->shouldReceive('connect')->once();
        $sftpMock->shouldReceive('connect')->once();

        $executor = new RemoteExecutorTestHelper();
        $executor->ssh = $sshMock;
        $executor->sftp = $sftpMock;

        $executor->connectBoth();

        // PHPUnit requires at least one assertion
        $this->assertTrue(true);
    }
}

/**
 * Helper class to bypass RemoteExecutor constructor for connectBoth test.
 */
class RemoteExecutorTestHelper extends RemoteExecutor
{
    public function __construct()
    {
        // Bypass parent constructor
    }
}
