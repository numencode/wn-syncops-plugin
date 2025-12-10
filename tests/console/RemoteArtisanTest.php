<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use PluginTestCase;
use NumenCode\SyncOps\Console\RemoteArtisan;

class RemoteArtisanTest extends PluginTestCase
{
    /**
     * Bind fake console commands to satisfy Winter’s console kernel.
     * We only need to ensure that all known syncops commands are bound
     * so that Artisan bootstrap does not complain during tests.
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
        app()->instance('command.syncops.project_pull', $fake());
        app()->instance('command.syncops.remote_artisan', $fake());
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

    public function setUp(): void
    {
        if (function_exists('app')) {
            self::bindFakeCommands();
        }

        parent::setUp();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: handle
     * When no artisan sub-command is provided, the command should print an
     * error message and return FAILURE without attempting any remote calls.
     */
    public function testHandleFailsWhenNoArtisanCommandProvided(): void
    {
        $cmd = Mockery::mock(RemoteArtisan::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('server')->andReturn('production');
        $cmd->shouldReceive('argument')->with('artisanCommand')->andReturn([]);

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')
            ->once()
            ->with(Mockery::pattern('/No artisan command provided/i'));

        // No remote execution should be attempted
        $cmd->shouldNotReceive('runRemoteAndPrint');

        $result = $cmd->handle();

        $this->assertSame(RemoteArtisan::FAILURE, $result);
    }

    /**
     * Test function: handle
     * When a valid artisan command is provided, the command should build a
     * "php artisan ..." command, delegate execution to runRemoteAndPrint(),
     * and return SUCCESS on completion.
     */
    public function testHandleRunsRemoteArtisanCommandSuccessfully(): void
    {
        $server = 'production';
        $artisanArgs = ['cache:clear', '--force'];
        $expectedCommandParts = [['php', 'artisan', 'cache:clear', '--force']];

        $cmd = Mockery::mock(RemoteArtisan::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('argument')->with('artisanCommand')->andReturn($artisanArgs);

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('info')
            ->once()
            ->with(Mockery::pattern('/executed successfully/i'));

        $cmd->shouldReceive('runRemoteAndPrint')
            ->once()
            ->with($server, $expectedCommandParts)
            ->andReturn("Application cache cleared\n");

        $result = $cmd->handle();

        $this->assertSame(RemoteArtisan::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * If remote execution throws an exception (e.g. SSH failure or non-zero
     * exit code translated to a RuntimeException), the command should catch
     * the error, print a descriptive message, and return FAILURE.
     */
    public function testHandleReturnsFailureWhenRemoteExecutionThrowsException(): void
    {
        $server = 'staging';
        $artisanArgs = ['config:cache'];

        $cmd = Mockery::mock(RemoteArtisan::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('server')->andReturn($server);
        $cmd->shouldReceive('argument')->with('artisanCommand')->andReturn($artisanArgs);

        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();
        $cmd->shouldReceive('comment')->zeroOrMoreTimes();
        $cmd->shouldReceive('error')->atLeast()->once();

        $cmd->shouldReceive('runRemoteAndPrint')
            ->once()
            ->with($server, [['php', 'artisan', 'config:cache']])
            ->andThrow(new \RuntimeException('SSH connection failed'));

        $result = $cmd->handle();

        $this->assertSame(RemoteArtisan::FAILURE, $result);
    }
}
