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
        // Bypass real constructor, we will manually set config and ssh.
    }
}

class ProjectDeployTest extends PluginTestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: handle
     * When the remote repository is not clean (has uncommitted changes),
     * the command should abort deployment, print instructions to run
     * project-pull, and return FAILURE.
     */
    public function testHandleAbortsWhenRemoteDirty(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config = [];

        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('remoteIsClean')->once()->andReturnFalse();
        $executor->ssh = $ssh;

        $cmd = Mockery::mock(ProjectDeploy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $cmd->shouldReceive('createExecutor')->once()->with('staging')->andReturn($executor);
        $cmd->shouldReceive('argument')->with('server')->andReturn('staging');

        // Options are not used in this early-exit branch, but safe to stub
        $cmd->shouldReceive('option')->with('fast')->andReturnFalse();
        $cmd->shouldReceive('option')->with('sudo')->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('warn')->atLeast()->once();
        $cmd->shouldReceive('error')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(ProjectDeploy::FAILURE, $result);
    }

    /**
     * Test function: handle / deploy / fastDeploy / mergeDeploy
     * Full (non-fast) deploy should:
     * - Put the app in maintenance mode (php artisan down),
     * - Clear caches,
     * - Perform a merge-based deploy,
     * - Rebuild caches,
     * - Bring the app out of maintenance mode (php artisan up),
     * and finally return SUCCESS.
     */
    public function testFullDeployRunsMaintenanceAndCacheCommands(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config['project']['branch_main'] = 'main';
        $executor->config['project']['branch_prod'] = 'prod';

        $ssh = Mockery::mock(SshExecutor::class);
        $executor->ssh = $ssh;

        $ssh->shouldReceive('remoteIsClean')->once()->andReturnTrue();

        // deploy(): maintenance mode
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['php', 'artisan', 'down']]);

        // deploy(): first cache clear
        $ssh->shouldReceive('runAndPrint')
            ->twice()
            ->with([
                ['php', 'artisan', 'route:clear'],
                ['php', 'artisan', 'cache:clear'],
            ]);

        // fastDeploy() -> mergeDeploy(): fetch + merge
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['git', 'fetch'],
                ['git', 'merge', 'origin/main'],
            ])
            ->andReturn('ok');

        // mergeDeploy(): push configured branch_prod
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['git', 'push', 'origin', 'prod']]);

        // deploy(): bring app out of maintenance mode
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['php', 'artisan', 'up']]);

        $cmd = Mockery::mock(ProjectDeploy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $cmd->shouldReceive('createExecutor')->once()->with('prod')->andReturn($executor);
        $cmd->shouldReceive('argument')->with('server')->andReturn('prod');

        $cmd->shouldReceive('option')->with('fast')->andReturnFalse();
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
     * Test function: handle / fastDeploy / pullDeploy / afterDeploy
     * Fast deploy in pull mode succeeds and triggers composer install and migrations
     * when the git output contains "composer.lock" (without requiring options).
     */
    public function testFastPullDeploySuccessTriggersComposerAndMigrateFromComposerLock(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config['project']['branch_main'] = false; // pull-based deploy

        $ssh = Mockery::mock(SshExecutor::class);
        $executor->ssh = $ssh;

        $ssh->shouldReceive('remoteIsClean')->once()->andReturnTrue();

        // pullDeploy: git pull
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['git', 'pull']])
            ->andReturn('Updated files including composer.lock');

        // afterDeploy: composer install
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['composer', 'install', '--no-dev'],
            ])
            ->andReturn('composer done');

        // afterDeploy: migrations
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['php', 'artisan', 'winter:up'],
            ])
            ->andReturn('migrated');

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
     * Test function: handle / fastDeploy / pullDeploy / afterDeploy
     * Fast deploy in pull mode should also trigger composer and migrations
     * when the --composer option is explicitly provided, even if the output
     * does not contain "composer.lock".
     */
    public function testFastPullDeployComposerAndMigrateTriggeredByOptions(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config['project']['branch_main'] = false;

        $ssh = Mockery::mock(SshExecutor::class);
        $executor->ssh = $ssh;

        $ssh->shouldReceive('remoteIsClean')->once()->andReturnTrue();

        // pullDeploy: git pull without composer.lock in output
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['git', 'pull']])
            ->andReturn('Updated files without lock');

        // afterDeploy: composer install due to option('composer') === true
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['composer', 'install', '--no-dev'],
            ])
            ->andReturn('composer done');

        // afterDeploy: migrations also triggered because option('composer') is true
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['php', 'artisan', 'winter:up'],
            ])
            ->andReturn('migrated');

        $cmd = Mockery::mock(ProjectDeploy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $cmd->shouldReceive('createExecutor')->once()->with('prod')->andReturn($executor);
        $cmd->shouldReceive('argument')->with('server')->andReturn('prod');

        $cmd->shouldReceive('option')->with('fast')->andReturnTrue();
        $cmd->shouldReceive('option')->with('sudo')->andReturnFalse();
        $cmd->shouldReceive('option')->with('composer')->andReturnTrue();
        $cmd->shouldReceive('option')->with('migrate')->andReturnFalse();

        $cmd->shouldReceive('newLine')->atLeast()->once();
        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('info')->atLeast()->once();

        $result = $cmd->handle();

        $this->assertSame(ProjectDeploy::SUCCESS, $result);
    }

    /**
     * Test function: handle / fastDeploy / pullDeploy
     * When a pull-based deploy (branch_main = false) has a merge CONFLICT,
     * the command should reset hard, report the failure, and return FAILURE.
     */
    public function testPullDeployConflictResetsAndFails(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config['project']['branch_main'] = false;

        $ssh = Mockery::mock(SshExecutor::class);
        $executor->ssh = $ssh;

        $ssh->shouldReceive('remoteIsClean')->once()->andReturnTrue();

        // pullDeploy: git pull returns conflict text
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['git', 'pull']])
            ->andReturn('CONFLICT (content): Merge conflict');

        // pullDeploy: hard reset after conflict
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['git', 'reset', '--hard']])
            ->andReturn('reset done');

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
     * Test function: handle / fastDeploy / mergeDeploy / handleOwnership / wrapSudo
     * For merge-based fast deploy with sudo and configured permissions:
     * - root_user ownership is applied with sudo,
     * - git fetch/merge is performed,
     * - the configured branch is pushed,
     * - web_folders ownership is applied with sudo for each folder, and the deployment returns SUCCESS.
     */
    public function testMergeDeployPushesBranchAndHandlesOwnershipWithSudo(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config = [
            'project' => [
                'branch_main' => 'main',
                'branch_prod' => 'prod',
            ],
            'permissions' => [
                'root_user'   => 'root:root',
                'web_user'    => 'www-data:www-data',
                'web_folders' => 'storage, public/uploads',
            ],
        ];

        $ssh = Mockery::mock(SshExecutor::class);
        $executor->ssh = $ssh;

        $ssh->shouldReceive('remoteIsClean')->once()->andReturnTrue();

        // fastDeploy: handle root_user ownership with sudo chown . -R
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['sudo', 'chown', 'root:root', '-R', '.'],
            ]);

        // mergeDeploy: fetch + merge
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['git', 'fetch'],
                ['git', 'merge', 'origin/main'],
            ])
            ->andReturn('ok');

        // mergeDeploy: push configured branch
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([
                ['git', 'push', 'origin', 'prod'],
            ]);

        // handleOwnership is called twice:
        // - once inside afterDeploy(),
        // - once at the end of handle().
        // Each time it chowns both 'storage' and 'public/uploads'.
        $ssh->shouldReceive('runAndPrint')
            ->twice()
            ->with([
                ['sudo', 'chown', 'www-data:www-data', '-R', 'storage'],
            ]);
        $ssh->shouldReceive('runAndPrint')
            ->twice()
            ->with([
                ['sudo', 'chown', 'www-data:www-data', '-R', 'public/uploads'],
            ]);

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

    /**
     * Test function: handleOwnership
     * When the "web_folders" config value is provided as an array instead of a comma-separated string,
     * the method should still iterate over all folders, apply chown for each, and behave identically.
     */
    public function testHandleOwnershipSupportsArrayWebFolders(): void
    {
        $executor = new RemoteExecutorStub();
        $executor->config = [
            'permissions' => [
                'web_user'    => 'www-data:www-data',
                'web_folders' => ['storage', 'public/uploads'],
            ],
        ];

        $ssh = Mockery::mock(SshExecutor::class);
        $executor->ssh = $ssh;

        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['chown', 'www-data:www-data', '-R', 'storage']]);
        $ssh->shouldReceive('runAndPrint')
            ->once()
            ->with([['chown', 'www-data:www-data', '-R', 'public/uploads']]);

        $cmd = Mockery::mock(ProjectDeploy::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('line')->atLeast()->once();
        $cmd->shouldReceive('newLine')->atLeast()->once();

        // Call handleOwnership directly via a closure
        (function () use ($executor) {
            $this->handleOwnership($executor, false);
        })->call($cmd);

        $this->assertTrue(true);
    }

    /**
     * Test function: handle
     * If an exception (e.g. during executor creation or remote calls) is thrown,
     * handle() should catch it, print error messages, and return FAILURE.
     */
    public function testHandleCatchesExceptionAndReturnsFailure(): void
    {
        $cmd = Mockery::mock(ProjectDeploy::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $cmd->shouldReceive('argument')->with('server')->andReturn('broken');
        $cmd->shouldReceive('createExecutor')
            ->once()
            ->with('broken')
            ->andThrow(new \RuntimeException('connection failed'));

        $cmd->shouldReceive('error')->atLeast()->once();
        $cmd->shouldReceive('newLine')->zeroOrMoreTimes();
        $cmd->shouldReceive('line')->zeroOrMoreTimes();

        // fast/sudo/composer/migrate options are not reached here, so no need to stub

        $result = $cmd->handle();

        $this->assertSame(ProjectDeploy::FAILURE, $result);
    }
}
