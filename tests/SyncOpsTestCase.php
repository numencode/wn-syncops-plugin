<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Tests;

use Illuminate\Support\Facades\File;
use NumenCode\SyncOps\Classes\ConnectionConfig;
use NumenCode\SyncOps\Classes\RemoteServer;
use NumenCode\SyncOps\Tests\Fixtures\FakeRemoteServer;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use System\Tests\Bootstrap\PluginTestCase;

/**
 * Base test case: a "test" connection and a fake server that the commands get from the container.
 */
abstract class SyncOpsTestCase extends PluginTestCase
{
    protected FakeRemoteServer $server;

    protected string $keyFile;

    public function setUp(): void
    {
        parent::setUp();

        $this->keyFile = (string) tempnam(sys_get_temp_dir(), 'syncops-key');
        file_put_contents($this->keyFile, 'not a real key');

        config(['syncops.connections' => ['test' => $this->connection()]]);
        config(['syncops.backup_path' => storage_path('temp/syncops-tests/backups')]);
        config(['syncops.backup_keep' => null]);

        $this->server = new FakeRemoteServer(ConnectionConfig::fromConfig('test'));
        $this->app->bind(RemoteServer::class, fn () => $this->server);
    }

    public function tearDown(): void
    {
        @unlink($this->keyFile);
        File::deleteDirectory(storage_path('temp/syncops-tests'));

        parent::tearDown();
    }

    /**
     * A valid connection; $overrides are merged per block (e.g. ['project' => ['php' => 'php8.4']]).
     *
     * @param array<string, array<string, mixed>> $overrides
     * @return array<string, mixed>
     */
    protected function connection(array $overrides = []): array
    {
        $connection = [
            'ssh' => [
                'host'             => 'server.test',
                'port'             => 22,
                'username'         => 'deploy',
                'key_path'         => $this->keyFile,
                'host_fingerprint' => 'SHA256:fake',
            ],
            'project' => [
                'path'        => '/srv/project',
                'branch_prod' => 'prod',
                'branch_main' => 'main',
            ],
            'database' => [
                'database' => 'remote_db',
                'username' => 'remote_user',
                'password' => 'Secret"Pa$$&word',
                'tables'   => [],
            ],
        ];

        foreach ($overrides as $block => $values) {
            $connection[$block] = array_merge($connection[$block] ?? [], $values);
        }

        return $connection;
    }

    /**
     * Replaces the "test" connection and rebuilds the fake server with it.
     *
     * @param array<string, array<string, mixed>> $overrides
     */
    protected function useConnection(array $overrides): void
    {
        config(['syncops.connections' => ['test' => $this->connection($overrides)]]);
        $results = $this->server->results;
        $this->server = new FakeRemoteServer(ConnectionConfig::fromConfig('test'));
        $this->server->results = $results;
    }

    /**
     * Runs a command like on the command line (tokens are parsed as with "php artisan …").
     *
     * @return array{0: int, 1: string} [exit code, output without colors]
     */
    protected function runCommand(string $name, array $tokens = []): array
    {
        $class = \NumenCode\SyncOps\Plugin::COMMANDS['syncops.' . str_replace('-', '_', substr($name, strlen('syncops:')))];

        /** @var \Illuminate\Console\Command $command */
        $command = new $class();
        $command->setLaravel($this->app);

        // Without the command name: the command parses its own arguments, as artisan does after finding it
        $input = new ArgvInput(['artisan', ...$tokens]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $code = $command->run($input, $output);

        return [$code, $output->fetch()];
    }
}
