<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Tests\Integration;

use NumenCode\SyncOps\Classes\ConnectionConfig;
use NumenCode\SyncOps\Classes\RemoteServer;
use RuntimeException;
use System\Tests\Bootstrap\PluginTestCase;

/**
 * Tests against a real server. Skipped unless the environment variable SYNCOPS_TEST_SERVER names a
 * connection from config/syncops.php, e.g.:
 *
 *     SYNCOPS_TEST_SERVER=production php artisan winter:test -p NumenCode.SyncOps ...
 *
 * Only read-only commands and a private temporary directory are used on the server.
 *
 * @group integration
 */
class RealServerTest extends PluginTestCase
{
    protected RemoteServer $server;

    public function setUp(): void
    {
        parent::setUp();

        $name = (string) getenv('SYNCOPS_TEST_SERVER');

        if ($name === '') {
            $this->markTestSkipped('Set SYNCOPS_TEST_SERVER to a connection name to run the integration tests.');
        }

        $this->server = new RemoteServer(ConnectionConfig::fromConfig($name));
    }

    public function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->disconnect();
        }

        parent::tearDown();
    }

    public function testConnectsAndVerifiesTheHostKey(): void
    {
        $fingerprint = $this->server->fingerprint();

        $this->assertMatchesRegularExpression('/^SHA256:[A-Za-z0-9+\/]{43}$/', $fingerprint);

        if ($this->server->config->hostFingerprint !== null) {
            $this->assertSame($this->server->config->hostFingerprint, $fingerprint);
        }
    }

    public function testRunsCommandsInTheProjectWithExitCodesAndErrors(): void
    {
        $this->assertTrue($this->server->run(['test', '-f', 'artisan'])->successful(), 'Runs in the project path');

        $failed = $this->server->run(['ls', '/syncops-does-not-exist']);
        $this->assertNotSame(0, $failed->exitCode);
        $this->assertStringContainsString('syncops-does-not-exist', $failed->error, 'stderr is captured');

        $php = $this->server->run([...$this->server->php(), '-r', 'echo PHP_MAJOR_VERSION;']);
        $this->assertTrue($php->successful(), 'The configured PHP binary works');
    }

    public function testArgumentsArriveUnchanged(): void
    {
        $value = "it's \"quoted\" 100% ! \$HOME `id` \\ ;|& ~";

        $this->assertSame($value, $this->server->run(['printf', '%s', $value])->output);
    }

    public function testLiveOutputArrivesInChunks(): void
    {
        $chunks = [];
        $result = $this->server->runShell('echo one; sleep 1; echo two >&2; sleep 1; echo three', function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        $this->assertTrue($result->successful());
        $this->assertGreaterThanOrEqual(2, count($chunks));
        $this->assertSame("one\ntwo\nthree\n", implode('', $chunks), 'stderr is included in live output');
    }

    public function testTemporaryDirectoriesArePrivateAndRemoved(): void
    {
        $directory = $this->server->makeTempDirectory();

        $this->assertMatchesRegularExpression('#/syncops\.[A-Za-z0-9]{8}$#', $directory);
        $this->assertSame('700', trim($this->server->runShell('stat -c %a ' . RemoteServer::quote($directory), null, false)->output));

        $this->server->putContents($directory . '/file.txt', 'content');
        $this->assertSame(7, $this->server->size($directory . '/file.txt'));

        $this->server->remove($directory);
        $this->assertFalse($this->server->runShell('test -e ' . RemoteServer::quote($directory), null, false)->successful());
    }

    public function testListsFilesWithSizes(): void
    {
        $files = $this->server->listFiles($this->server->projectPath('storage/app'));

        $this->assertIsArray($files);

        foreach ($files as $path => $size) {
            $this->assertIsString($path);
            $this->assertIsInt($size);
            $this->assertStringStartsNotWith('/', $path);
        }
    }

    public function testATimeoutFailsClearlyAndTheNextCommandWorks(): void
    {
        $config = $this->server->config;
        $server = new RemoteServer(new ConnectionConfig(...array_merge(get_object_vars($config), ['timeout' => 1])));

        try {
            $server->runShell('sleep 3');
            $this->fail('The command must time out');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not finish within 1 seconds', $e->getMessage());
        }

        $this->assertSame('ok', $server->runShell('printf ok')->output, 'A new connection is used after a timeout');
        $server->disconnect();
    }
}
