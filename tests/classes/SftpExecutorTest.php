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

    /**
     * Bind fake console commands to satisfy Winter’s kernel bootstrap.
     */
    protected static function bindFakeCommands(): void
    {
        // Avoid resolving real commands; we just need placeholders.
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
        ];
    }

    public function tearDown(): void
    {
        // Verify and close Mockery
        Mockery::close();

        // Ensure temporary files are removed even when a test throws before cleanup
        $fixture    = __DIR__ . '/_fixture_local.txt';
        $downloaded = __DIR__ . '/_downloaded.txt';

        clearstatcache();

        if (is_file($fixture)) {
            @unlink($fixture);
        }

        if (is_file($downloaded)) {
            @unlink($downloaded);
        }

        parent::tearDown();
    }

    /**
     * Helper to set the protected $sftp property on the executor instance via Reflection.
     */
    protected function setSftpMock(SftpExecutor $executor, SFTP $sftpMock): void
    {
        $reflection = new \ReflectionClass($executor);
        $property   = $reflection->getProperty('sftp');
        $property->setAccessible(true);
        $property->setValue($executor, $sftpMock);
    }

    /**
     * Test function: connect
     * Test that connect() returns an SFTP instance on successful connection.
     */
    public function testConnectSuccess(): void
    {
        // Avoid real network: pre-inject an SFTP instance so connect() returns it without dialing out
        $sftpMock = Mockery::mock(SFTP::class);

        $executor = new SftpExecutor($this->server, $this->config, $this->credentials);
        $this->setSftpMock($executor, $sftpMock);

        $result = $executor->connect();

        $this->assertSame($sftpMock, $result);
        $this->assertInstanceOf(SFTP::class, $result);
    }

    /**
     * Test function: connect
     * Test that connect() throws when connection/login fails (using an obviously invalid host).
     */
    public function testConnectFailureThrowsException(): void
    {
        $executor = new SftpExecutor($this->server, [
            'host'     => 'nonexistent-host.invalid',
            'port'     => 22,
            'username' => 'user',
        ], 'wrong-password');

        // phpseclib may throw different Throwable types depending on environment,
        // so we just require that *something* fails loudly.
        $this->expectException(\Throwable::class);

        $executor->connect();
    }

    /**
     * Test function: upload
     * Test that upload() successfully uploads a file using SFTP.
     */
    public function testUploadSuccess(): void
    {
        $localFile  = __DIR__ . '/_fixture_local.txt';
        $remoteFile = '/remote/file.txt';

        file_put_contents($localFile, 'content');

        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('put')
            ->once()
            ->with($remoteFile, 'content')
            ->andReturnTrue();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $executor->upload($localFile, $remoteFile);

        // Cleanup created file
        @unlink($localFile);

        // At least one assertion so PHPUnit doesn't mark as risky
        $this->assertTrue(true);
    }

    /**
     * Test function: upload
     * Test that upload() throws an exception when upload fails.
     */
    public function testUploadFailureThrowsException(): void
    {
        $localFile  = __DIR__ . '/_fixture_local.txt';
        $remoteFile = '/remote/file.txt';

        file_put_contents($localFile, 'data');

        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('put')
            ->once()
            ->with($remoteFile, 'data')
            ->andReturnFalse();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to upload file/');

        $executor->upload($localFile, $remoteFile);

        @unlink($localFile);
    }

    /**
     * Test function: download
     * Test that download() successfully retrieves a file from the remote server.
     */
    public function testDownloadSuccess(): void
    {
        $localFile  = __DIR__ . '/_downloaded.txt';
        $remoteFile = '/remote/file.txt';

        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('get')
            ->once()
            ->with($remoteFile, $localFile)
            ->andReturnTrue();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();
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
        $localFile  = __DIR__ . '/_downloaded.txt';
        $remoteFile = '/remote/file.txt';

        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('get')
            ->once()
            ->with($remoteFile, $localFile)
            ->andReturnFalse();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to download file/');

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
        $sftpMock->shouldReceive('nlist')
            ->once()
            ->with('/remote', true)
            ->andReturn($entries);

        $sftpMock->shouldReceive('is_file')->andReturnUsing(function ($file) {
            return !str_contains($file, 'ignored');
        });

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();
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
     * Test function: listFilesRecursively
     * Test that listFilesRecursively() returns an empty array when directory is empty.
     */
    public function testListFilesRecursivelyReturnsEmptyArrayWhenNoEntries(): void
    {
        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('nlist')
            ->once()
            ->with('/empty', true)
            ->andReturn([]);

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $this->assertSame([], $executor->listFilesRecursively('/empty'));
    }

    /**
     * Test function: filesizeRemote
     * Test that filesizeRemote() returns correct integer file size.
     */
    public function testFilesizeRemoteReturnsInt(): void
    {
        $sftpMock = Mockery::mock(SFTP::class);
        $sftpMock->shouldReceive('filesize')
            ->once()
            ->with('/remote/file.txt')
            ->andReturn(1234);

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();
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
        $sftpMock->shouldReceive('filesize')
            ->once()
            ->with('/remote/missing.txt')
            ->andReturn(false);

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();
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
        $sftpMock->shouldReceive('file_exists')
            ->once()
            ->with('/remote/test.txt')
            ->andReturnTrue();

        $executor = Mockery::mock(SftpExecutor::class, [$this->server, $this->config, $this->credentials])
            ->makePartial();
        $executor->shouldReceive('connect')->andReturn($sftpMock);

        $this->assertTrue($executor->exists('/remote/test.txt'));
    }
}
