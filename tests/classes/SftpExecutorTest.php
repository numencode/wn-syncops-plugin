<?php namespace NumenCode\SyncOps\Tests\Classes;

use Mockery;
use PluginTestCase;
use phpseclib3\Net\SFTP;
use NumenCode\SyncOps\Classes\SftpExecutor;

class SftpExecutorTest extends PluginTestCase
{
    protected string $server = 'production';
    protected array $config;
    protected string $credentials = 'password';

    public function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'host'     => 'example.com',
            'port'     => 22,
            'username' => 'user',
        ];
    }

    public function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Test function: connect
     * Test that connect() returns an SFTP instance on successful connection.
     */
    public function testConnectSuccess(): void
    {
        // This will not assert login() directly, since connect() creates its own SFTP internally.
        // We'll just mock the class partially and assert we get an SFTP instance back.
        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // We cannot intercept internal new SFTP, so we just call it and assert successful type.
        try {
            $result = $executor->connect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Real SFTP connection not available in test environment.');
            return;
        }

        $this->assertInstanceOf(SFTP::class, $result);
    }

    /**
     * Test function: connect
     * Test that connect() throws an exception when connection fails.
     */
    public function testConnectFailureThrowsException(): void
    {
        // Expect *any* exception on bad connection (phpseclib typically throws RuntimeException or Error)
        $executor = new SftpExecutor($this->server, [
            'host'     => 'nonexistent-host.invalid',
            'port'     => 22,
            'username' => 'user',
        ], 'wrong-password');

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/(SFTP|connect|failed)/i');

        $executor->connect();
    }

    /**
     * Test function: upload
     * Test that upload() successfully uploads a file using SFTP.
     */
    public function testUploadSuccess(): void
    {
        $localFile = __DIR__ . '/_fixture_local.txt';
        file_put_contents($localFile, 'content');
        $remoteFile = '/remote/file.txt';

        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('put')
            ->once()
            ->with($remoteFile, 'content')
            ->andReturnTrue();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $executor->upload($localFile, $remoteFile);

        unlink($localFile);

        $this->assertTrue(true);
    }

    /**
     * Test function: upload
     * Test that upload() throws an exception when upload fails.
     */
    public function testUploadFailureThrowsException(): void
    {
        $localFile = __DIR__ . '/_fixture_local.txt';
        file_put_contents($localFile, 'data');
        $remoteFile = '/remote/file.txt';

        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('put')
            ->once()
            ->andReturnFalse();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $this->expectException(\RuntimeException::class);
        $executor->upload($localFile, $remoteFile);

        unlink($localFile);
    }

    /**
     * Test function: download
     * Test that download() successfully retrieves a file from the remote server.
     */
    public function testDownloadSuccess(): void
    {
        $localFile = __DIR__ . '/_downloaded.txt';
        $remoteFile = '/remote/file.txt';

        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('get')
            ->once()
            ->with($remoteFile, $localFile)
            ->andReturnTrue();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $executor->download($remoteFile, $localFile);

        $this->assertTrue(true);
    }

    /**
     * Test function: download
     * Test that download() throws an exception when the operation fails.
     */
    public function testDownloadFailureThrowsException(): void
    {
        $localFile = __DIR__ . '/_downloaded.txt';
        $remoteFile = '/remote/file.txt';

        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('get')->once()->andReturnFalse();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $this->expectException(\RuntimeException::class);
        $executor->download($remoteFile, $localFile);
    }

    /**
     * Test function: listFilesRecursively
     * Test that listFilesRecursively() filters and returns valid files only.
     */
    public function testListFilesRecursivelyFiltersEntries(): void
    {
        $entries = [
            './file1.txt',
            './folder/file2.txt',
            './thumb/img.jpg',
            './.env',
            './.gitignore',
            './folder/../ignored',
            './resized/photo.png',
        ];

        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('nlist')->once()->andReturn($entries);
        $sftpMock->shouldReceive('is_file')->andReturnUsing(function ($file) {
            return !str_contains($file, 'ignored');
        });

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $result = $executor->listFilesRecursively('/remote');

        $expected = [
            '/remote/file1.txt',
            '/remote/folder/file2.txt',
            '/remote/.gitignore',
        ];

        sort($result);
        sort($expected);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test function: filesizeRemote
     * Test that filesizeRemote() returns correct integer file size.
     */
    public function testFilesizeRemoteReturnsInt(): void
    {
        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('filesize')->once()->with('/remote/file.txt')->andReturn(1234);

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $this->assertSame(1234, $executor->filesizeRemote('/remote/file.txt'));
    }

    /**
     * Test function: filesizeRemote
     * Test that filesizeRemote() returns null when file size retrieval fails.
     */
    public function testFilesizeRemoteReturnsNullOnFailure(): void
    {
        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('filesize')->once()->andReturn(false);

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $this->assertNull($executor->filesizeRemote('/remote/missing.txt'));
    }

    /**
     * Test function: exists
     * Test that exists() delegates to SFTP::file_exists and returns true.
     */
    public function testExistsDelegatesToSftp(): void
    {
        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('file_exists')->once()->with('/remote/test.txt')->andReturnTrue();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $this->assertTrue($executor->exists('/remote/test.txt'));
    }
}
