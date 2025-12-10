<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use PluginTestCase;
use NumenCode\SyncOps\Console\Validate;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class ValidateTest extends PluginTestCase
{
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
        app()->instance('command.syncops.project_p ull', $fake());
        app()->instance('command.syncops.validate', $fake());
    }

    /**
     * Ensure fake commands are bound before PluginTestCase bootstraps Artisan.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (function_exists('app')) {
            self::bindFakeCommands();
        }
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: handle
     * When no connections are defined in config/syncops.php, the command
     * should report the misconfiguration and return FAILURE.
     */
    public function testHandleFailsWhenNoConnectionsConfigured(): void
    {
        config()->set('syncops.connections', []);

        $cmd = Mockery::mock(Validate::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('option')->with('server')->andReturnNull();
        $cmd->shouldReceive('option')->with('connect')->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('error')
            ->once()
            ->with(Mockery::pattern('/No SyncOps connections defined/i'));

        $result = $cmd->handle();

        $this->assertSame(Validate::FAILURE, $result);
    }

    /**
     * Test function: handle
     * When a specific server is requested that does not exist in the
     * connections array, the command should report the problem and return FAILURE.
     */
    public function testHandleFailsWhenRequestedServerDoesNotExist(): void
    {
        config()->set('syncops.connections', [
            'production' => [
                'ssh' => [
                    'host'     => 'example.com',
                    'username' => 'deploy',
                    'password' => 'secret',
                ],
                'project' => [
                    'path'        => '/var/www/app',
                    'branch_main' => 'main',
                ],
            ],
        ]);

        $cmd = Mockery::mock(Validate::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('option')->with('server')->andReturn('staging');
        $cmd->shouldReceive('option')->with('connect')->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('error')
            ->once()
            ->with(Mockery::pattern('/No SyncOps connection found for server key \'staging\'/i'));

        $result = $cmd->handle();

        $this->assertSame(Validate::FAILURE, $result);
    }

    /**
     * Test function: handle
     * For a valid single server configuration, the static checks should pass,
     * no SSH connections should be attempted when --connect is not used,
     * and the command should return SUCCESS.
     */
    public function testHandleValidatesSingleServerWithStaticChecksOnly(): void
    {
        config()->set('syncops.connections', [
            'production' => [
                'ssh' => [
                    'host'     => 'example.com',
                    'port'     => 22,
                    'username' => 'deploy',
                    'password' => 'secret',
                ],
                'project' => [
                    'path'        => '/var/www/app',
                    'branch_main' => 'main',
                    'branch_prod' => 'prod',
                ],
            ],
        ]);

        $cmd = Mockery::mock(Validate::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('option')->with('server')->andReturn('production');
        $cmd->shouldReceive('option')->with('connect')->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')
            ->atLeast()
            ->once()
            ->with(Mockery::pattern('/Static configuration looks valid/i'));
        $cmd->shouldReceive('error')->zeroOrMoreTimes();
        $cmd->shouldReceive('warn')->zeroOrMoreTimes();

        // --connect is false, so no executor should be created
        $cmd->shouldReceive('createExecutor')->never();

        $result = $cmd->handle();

        $this->assertSame(Validate::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * When multiple connections are defined and one of them has an invalid
     * configuration (e.g. missing ssh block), the command should report
     * errors and return FAILURE.
     */
    public function testHandleReportsInvalidConfigurationsAndReturnsFailure(): void
    {
        config()->set('syncops.connections', [
            'good' => [
                'ssh' => [
                    'host'     => 'example.com',
                    'username' => 'deploy',
                    'password' => 'secret',
                ],
                'project' => [
                    'path'        => '/var/www/app',
                    'branch_main' => 'main',
                ],
            ],
            'bad' => [
                // Missing ssh block entirely
                'project' => [
                    'path' => '/var/www/bad',
                ],
            ],
        ]);

        $cmd = Mockery::mock(Validate::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('option')->with('server')->andReturnNull();
        $cmd->shouldReceive('option')->with('connect')->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();

        $cmd->shouldReceive('error')
            ->atLeast()
            ->once()
            ->with(Mockery::pattern('/missing \'ssh\' configuration block/i'));

        $result = $cmd->handle();

        $this->assertSame(Validate::FAILURE, $result);
    }

    /**
     * Test function: handle
     * When --connect is specified and the configuration is valid, the command
     * should attempt SSH connectivity via RemoteExecutor::connectBoth() and
     * return SUCCESS if all servers connect successfully.
     */
    public function testHandleAttemptsConnectivityWhenConnectOptionIsUsed(): void
    {
        config()->set('syncops.connections', [
            'staging' => [
                'ssh' => [
                    'host'     => 'staging.example.com',
                    'username' => 'deploy',
                    'password' => 'secret',
                ],
                'project' => [
                    'path'        => '/srv/app',
                    'branch_main' => 'develop',
                ],
            ],
        ]);

        $executor = Mockery::mock(RemoteExecutor::class);
        $executor->shouldReceive('connectBoth')->once();

        $cmd = Mockery::mock(Validate::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('option')->with('server')->andReturnNull();
        $cmd->shouldReceive('option')->with('connect')->andReturnTrue();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();
        $cmd->shouldReceive('warn')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')->zeroOrMoreTimes();

        $cmd->shouldReceive('createExecutor')
            ->once()
            ->with('staging')
            ->andReturn($executor);

        $result = $cmd->handle();

        $this->assertSame(Validate::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * When --connect is specified but SSH connectivity fails for a server
     * (e.g. RemoteExecutor::connectBoth() throws), the command should report
     * the failure and return FAILURE overall.
     */
    public function testHandleReportsConnectivityFailuresAndReturnsFailure(): void
    {
        config()->set('syncops.connections', [
            'staging' => [
                'ssh' => [
                    'host'     => 'staging.example.com',
                    'username' => 'deploy',
                    'password' => 'secret',
                ],
                'project' => [
                    'path'        => '/srv/app',
                    'branch_main' => 'develop',
                ],
            ],
        ]);

        $executor = Mockery::mock(RemoteExecutor::class);
        $executor->shouldReceive('connectBoth')
            ->once()
            ->andThrow(new \RuntimeException('connection refused'));

        $cmd = Mockery::mock(Validate::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('option')->with('server')->andReturnNull();
        $cmd->shouldReceive('option')->with('connect')->andReturnTrue();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')->zeroOrMoreTimes();
        $cmd->shouldReceive('warn')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')
            ->atLeast()
            ->once()
            ->with(Mockery::pattern('/SSH connectivity failed/i'));

        $cmd->shouldReceive('createExecutor')
            ->once()
            ->with('staging')
            ->andReturn($executor);

        $result = $cmd->handle();

        $this->assertSame(Validate::FAILURE, $result);
    }
}
