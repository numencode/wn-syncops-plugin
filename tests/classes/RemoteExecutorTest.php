<?php namespace NumenCode\SyncOps\Tests\Classes;

use Mockery;
use PluginTestCase;
use NumenCode\SyncOps\Classes\SshExecutor;
use NumenCode\SyncOps\Classes\SftpExecutor;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class RemoteExecutorTest extends PluginTestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: __construct
     * It should throw an exception when no config exists for the given server.
     */
    public function testConstructorThrowsIfNoConfig(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No config for server invalid_server');

        // Explicitly set an empty array to simulate an invalid configuration.
        config()->set('syncops.connections.invalid_server', []);

        new RemoteExecutor('invalid_server');
    }

    /**
     * Test function: __construct
     * When password credentials are provided, they should be used directly.
     */
    public function testConstructorWithPassword(): void
    {
        $server = 'server1';
        $password = 'secret';

        config()->set("syncops.connections.$server.ssh", [
            'host'     => 'example.com',
            'username' => 'user',
            'password' => $password,
            'key_path' => '',
        ]);

        // Mock PublicKeyLoader to ensure it's not used when password is present
        $mockKeyLoader = Mockery::mock('alias:phpseclib3\Crypt\PublicKeyLoader');
        $mockKeyLoader->shouldNotReceive('load');

        $executor = new RemoteExecutor($server);

        $this->assertInstanceOf(SshExecutor::class, $executor->ssh);
        $this->assertInstanceOf(SftpExecutor::class, $executor->sftp);
    }

    /**
     * Test function: __construct
     * When key_path is set, PublicKeyLoader::load() should be called.
     */
    public function testConstructorWithPublicKey(): void
    {
        $server = 'server2';
        $keyPath = __DIR__ . '/mock_key.pem';
        file_put_contents($keyPath, 'FAKE_KEY');

        config()->set("syncops.connections.$server.ssh", [
            'host'     => 'example.com',
            'username' => 'user',
            'password' => '',
            'key_path' => $keyPath,
        ]);

        $mockKeyLoader = Mockery::mock('alias:phpseclib3\Crypt\PublicKeyLoader');
        $mockKeyLoader->shouldReceive('load')
            ->once()
            ->with('FAKE_KEY')
            ->andReturn('PUBLIC_KEY');

        $executor = new RemoteExecutor($server);

        $this->assertInstanceOf(SshExecutor::class, $executor->ssh);
        $this->assertInstanceOf(SftpExecutor::class, $executor->sftp);

        @unlink($keyPath);
    }

    /**
     * Test function: connectBoth
     * It should call connect() on both SSH and SFTP executors.
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

        // Verify that both connect() calls occurred
        $sshMock->shouldHaveReceived('connect')->once();
        $sftpMock->shouldHaveReceived('connect')->once();

        // Add an explicit PHPUnit assertion to avoid "risky" flag
        $this->assertTrue(true, 'Mock expectations verified successfully.');
    }
}

/**
 * Helper class that bypasses RemoteExecutor's constructor
 * so we can manually inject mock executors.
 */
class RemoteExecutorTestHelper extends RemoteExecutor
{
    public function __construct()
    {
        // Skip parent constructor
    }
}
