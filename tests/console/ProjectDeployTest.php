<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use PluginTestCase;
use NumenCode\SyncOps\Classes\SshExecutor;
use NumenCode\SyncOps\Console\ProjectDeploy;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class RemoteExecutorStub extends RemoteExecutor
{
    public function __construct()
    {
        /* bypass parent */
    }
}

class ProjectDeployTest extends PluginTestCase
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
     * Test function: handle
     * Aborts with FAILURE when remote repo is not clean.
     */
    public function testHandleAbortsWhenRemoteDirty(): void
    {
        // Build a lightweight executor stub (extends RemoteExecutor)
        $executor = new RemoteExecutorStub();
        $executor->config = [];

        // Mock SSH executor
        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('remoteIsClean')->once()->andReturnFalse();
        $executor->ssh = $ssh;

        // Partial mock the command to control IO and options/arguments
        $cmd = Mockery::mock(ProjectDeploy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $cmd->shouldReceive('createExecutor')->once()->with('staging')->andReturn($executor);
        $cmd->shouldReceive('argument')->with('server')->andReturn('staging');
        $cmd->shouldReceive('option')->with('fast')->andReturnFalse();
        $cmd->shouldReceive('option')->with('sudo')->andReturnFalse();
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('warn')->atLeast()->once();
        $cmd->shouldReceive('error')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(ProjectDeploy::FAILURE, $result);
    }

    /**
     * Test function: handle/fastDeploy/pullDeploy/afterDeploy
     * Fast deploy in pull mode succeeds and triggers composer and migrate when composer.lock is present in output.
     */
    public function testFastPullDeploySuccessTriggersComposerAndMigrate(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config = [
            'branch_main' => false,
        ];

        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('remoteIsClean')->once()->andReturnTrue();
        // pullDeploy
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['git', 'pull']])
            ->andReturn("Updated files including composer.lock");
        // afterDeploy -> composer install
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['composer', 'install', '--no-dev']
            ])
            ->andReturn('composer done');
        // afterDeploy -> migrations
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['php', 'artisan', 'winter:up']
            ])
            ->andReturn('migrated');
        // handleOwnership invoked at end of handle (noop due to empty permissions in config)
        $executor->ssh = $ssh;

        $cmd = Mockery::mock(ProjectDeploy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $cmd->shouldReceive('createExecutor')->once()->with('prod')->andReturn($executor);
        $cmd->shouldReceive('argument')->with('server')->andReturn('prod');
        $cmd->shouldReceive('option')->with('fast')->andReturnTrue();
        $cmd->shouldReceive('option')->with('sudo')->andReturnFalse();
        $cmd->shouldReceive('option')->with('composer')->andReturnFalse();
        $cmd->shouldReceive('option')->with('migrate')->andReturnFalse();
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(ProjectDeploy::SUCCESS, $result);
    }

    /**
     * Test function: pullDeploy
     * When pull results contain CONFLICT, it resets and returns FAILURE from handle.
     */
    public function testPullDeployConflictResetsAndFails(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config = ['branch_main' => false];

        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('remoteIsClean')->once()->andReturnTrue();
        // First call: git pull returns conflict text
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['git', 'pull']])
            ->andReturn('CONFLICT (content): Merge conflict');
        // Then a hard reset is issued
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['git', 'reset', '--hard']])
            ->andReturn('reset done');
        $executor->ssh = $ssh;

        $cmd = Mockery::mock(ProjectDeploy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $cmd->shouldReceive('createExecutor')->once()->with('prod')->andReturn($executor);
        $cmd->shouldReceive('argument')->with('server')->andReturn('prod');
        $cmd->shouldReceive('option')->with('fast')->andReturnTrue();
        $cmd->shouldReceive('option')->with('sudo')->andReturnFalse();
        $cmd->shouldReceive('option')->with('composer')->andReturnFalse();
        $cmd->shouldReceive('option')->with('migrate')->andReturnFalse();
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('error')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(ProjectDeploy::FAILURE, $result);
    }

    /**
     * Test function: mergeDeploy + ownership + sudo
     * Verifies merge flow and that sudo is prepended when requested; also pushes configured branch.
     */
    public function testMergeDeployPushesBranchAndHandlesOwnershipWithSudo(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config = [
            'branch_main' => 'main',
            'branch'      => 'deploy',
            'permissions' => [
                'root_user'   => 'root:root',
                'web_user'    => 'www-data:www-data',
                'web_folders' => 'storage, public/uploads',
            ],
        ];

        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('remoteIsClean')->once()->andReturnTrue();
        // fastDeploy: because root_user is set, chown . with sudo
        $ssh->shouldReceive('runAndPrint')->once()->with([
            ['sudo', 'chown', 'root:root', '-R', '.']
        ]);
        // mergeDeploy: fetch + merge main
        $ssh->shouldReceive('runAndPrint')->once()->with([
            ['git', 'fetch'],
            ['git', 'merge', 'origin/main'],
        ])->andReturn('ok');
        // push configured branch
        $ssh->shouldReceive('runAndPrint')->once()->with([
            ['git', 'push', 'origin', 'deploy']
        ]);
        // afterDeploy: because neither composer option nor composer.lock returned, nothing extra
        // handleOwnership at end: chown web folders with sudo for each folder
        $ssh->shouldReceive('runAndPrint')->twice()->with([
            ['sudo', 'chown', 'www-data:www-data', '-R', 'storage'],
        ]);
        $ssh->shouldReceive('runAndPrint')->twice()->with([
            ['sudo', 'chown', 'www-data:www-data', '-R', 'public/uploads'],
        ]);

        $executor->ssh = $ssh;

        $cmd = Mockery::mock(ProjectDeploy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $cmd->shouldReceive('createExecutor')->once()->with('prod')->andReturn($executor);
        $cmd->shouldReceive('argument')->with('server')->andReturn('prod');
        $cmd->shouldReceive('option')->with('fast')->andReturnTrue();
        $cmd->shouldReceive('option')->with('sudo')->andReturnTrue();
        $cmd->shouldReceive('option')->with('composer')->andReturnFalse();
        $cmd->shouldReceive('option')->with('migrate')->andReturnFalse();
        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(ProjectDeploy::SUCCESS, $result);
    }
}
