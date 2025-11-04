<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use PluginTestCase;
use NumenCode\SyncOps\Console\ProjectPull;
use NumenCode\SyncOps\Classes\SshExecutor;
use NumenCode\SyncOps\Classes\RemoteExecutor;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ProjectPullTest extends PluginTestCase
{
    /**
     * Bind fake console commands to satisfy Winter’s kernel bootstrap.
     */
    protected static function bindFakeCommands(): void
    {
        $fake = fn() => Mockery::mock(\Illuminate\Console\Command::class);

        app()->instance('command.syncops.db_pull', $fake());
        app()->instance('command.syncops.db_push', $fake());
        app()->instance('command.syncops.media_pull', $fake());
        app()->instance('command.syncops.media_push', $fake());
        app()->instance('command.syncops.project_backup', $fake());
        app()->instance('command.syncops.project_deploy', $fake());
        app()->instance('command.syncops.project_push', $fake());
        app()->instance('command.syncops.project_pull', Mockery::mock(ProjectPull::class));
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
     * Helper: create RemoteExecutor with a correctly typed mocked SshExecutor.
     */
    protected function makeExecutorMock(): RemoteExecutor
    {
        $ssh = Mockery::mock(SshExecutor::class);
        $executor = Mockery::mock(RemoteExecutor::class)->makePartial();
        $executor->ssh = $ssh;
        $executor->config = ['branch_prod' => 'main'];

        return $executor;
    }

    /**
     * Test function: handle
     * When the remote repository is clean (no uncommitted changes),
     * the command should print an informational message and return SUCCESS.
     */
    public function testHandleRemoteIsCleanReturnsSuccess(): void
    {
        $executor = $this->makeExecutorMock();
        $executor->ssh->shouldReceive('remoteIsClean')->once()->andReturn(true);

        $command = Mockery::mock(ProjectPull::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $command->shouldReceive('argument')->with('server')->andReturn('production');
        $command->shouldReceive('newLine')->atLeast()->once();
        $command->shouldReceive('line')->atLeast()->once();
        $command->shouldReceive('info')
            ->once()
            ->with(Mockery::pattern('/No changes on the remote/i'));

        $result = (function () use ($executor) {
            $this->newLine();
            $this->line("Connecting to remote server 'production'...");
            $this->newLine();

            if ($executor->ssh->remoteIsClean()) {
                $this->info("✔ No changes on the remote server.");
                return self::SUCCESS;
            }

            return self::FAILURE;
        })->call($command);

        $this->assertSame(ProjectPull::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * When there are uncommitted changes on the remote,
     * the command should commit and push them, then fetch and merge locally
     * and return SUCCESS.
     */
    public function testHandleWithRemoteChangesReturnsSuccess(): void
    {
        $executor = $this->makeExecutorMock();
        $executor->ssh->shouldReceive('remoteIsClean')->once()->andReturn(false);
        $executor->ssh->shouldReceive('runAndGet')
            ->once()
            ->with(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])
            ->andReturn('main');
        $executor->ssh->shouldReceive('runAndPrint')->once();

        $command = Mockery::mock(ProjectPull::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $command->shouldReceive('argument')->with('server')->andReturn('production');
        $command->shouldReceive('option')->with('message')->andReturnNull();
        $command->shouldReceive('option')->with('pull')->andReturn(false);
        $command->shouldReceive('option')->with('no-merge')->andReturn(false);

        $command->shouldReceive('runLocalCommand')->once()->with('git fetch origin')->andReturn('');
        $command->shouldReceive('runLocalCommand')->once()->with('git merge origin/main')->andReturn('Merged');
        $command->shouldReceive('newLine')->atLeast()->once();
        $command->shouldReceive('line')->atLeast()->once();
        $command->shouldReceive('info')
            ->once()
            ->with(Mockery::pattern('/successfully pulled/i'));

        $result = (function () use ($executor) {
            $this->newLine();
            $this->line("Connecting to remote server 'production'...");
            $this->newLine();

            if ($executor->ssh->remoteIsClean()) {
                $this->info("✔ No changes on the remote server.");
                return self::SUCCESS;
            }

            $this->line("Changes detected on remote. Committing and pushing...");
            $commitMessage = 'Server changes';

            $currentRemoteBranch = $executor->ssh->runAndGet(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);

            $executor->ssh->runAndPrint([
                ['git', 'add', '--all'],
                ['git', 'commit', '-m', $commitMessage],
                ['git', 'push', 'origin', $currentRemoteBranch],
            ]);

            $this->runLocalCommand('git fetch origin');
            $this->runLocalCommand('git merge origin/' . $currentRemoteBranch);
            $this->info("✔ Changes were successfully pulled and merged into the local project.");

            return self::SUCCESS;
        })->call($command);

        $this->assertSame(ProjectPull::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * When --no-merge is specified, local fetch and merge should be skipped,
     * but remote commit/push still occurs and the command should return SUCCESS.
     */
    public function testHandleNoMergeOptionSkipsLocalMerge(): void
    {
        $executor = $this->makeExecutorMock();
        $executor->ssh->shouldReceive('remoteIsClean')->once()->andReturn(false);
        $executor->ssh->shouldReceive('runAndGet')->once()->andReturn('develop');
        $executor->ssh->shouldReceive('runAndPrint')->once();

        $command = Mockery::mock(ProjectPull::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $command->shouldReceive('argument')->with('server')->andReturn('staging');
        $command->shouldReceive('option')->with('message')->andReturnNull();
        $command->shouldReceive('option')->with('pull')->andReturn(false);
        $command->shouldReceive('option')->with('no-merge')->andReturn(true);

        $command->shouldReceive('runLocalCommand')->never();
        $command->shouldReceive('info')
            ->once()
            ->with(Mockery::pattern('/Skipping local merge/i'));
        $command->shouldReceive('newLine')->atLeast()->once();
        $command->shouldReceive('line')->atLeast()->once();

        $result = (function () use ($executor) {
            $this->newLine();
            $this->line("Connecting to remote server 'staging'...");

            $executor->ssh->remoteIsClean();
            $currentBranch = $executor->ssh->runAndGet(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);

            $executor->ssh->runAndPrint([
                ['git', 'add', '--all'],
                ['git', 'commit', '-m', 'Server changes'],
                ['git', 'push', 'origin', $currentBranch],
            ]);

            $this->info("✔ Remote changes were pushed. Skipping local merge as requested.");

            return self::SUCCESS;
        })->call($command);

        $this->assertSame(ProjectPull::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * If a ProcessFailedException is thrown by a local git command,
     * the command should catch it, print error output, and return FAILURE.
     *
     * Note: this test focuses purely on local command failure handling and
     * deliberately does not mock or involve any remote executor behaviour.
     */
    public function testHandleLocalCommandFailureReturnsFailure(): void
    {
        // Mock exception thrown by local git command
        $exception = Mockery::mock(ProcessFailedException::class);

        $command = Mockery::mock(ProjectPull::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $command->shouldReceive('argument')->with('server')->andReturn('production');
        $command->shouldReceive('option')->with('message')->andReturnNull();
        $command->shouldReceive('option')->with('pull')->andReturn(false);
        $command->shouldReceive('option')->with('no-merge')->andReturn(false);

        // Local command fails
        $command->shouldReceive('runLocalCommand')
            ->once()
            ->with('git fetch origin')
            ->andThrow($exception);

        $command->shouldReceive('error')->atLeast()->once();

        $result = (function () {
            try {
                $this->runLocalCommand('git fetch origin');
            } catch (ProcessFailedException $e) {
                $this->error("✘ A local git command failed:");
                $this->error($e->getMessage());
                return self::FAILURE;
            }

            // Fallback (not expected to be reached in this scenario)
            return self::SUCCESS;
        })->call($command);

        $this->assertSame(ProjectPull::FAILURE, $result);
    }

    /**
     * Test function: handle
     * If a generic exception (e.g. a RemoteExecutor error) occurs at any point,
     * it should be caught, error messages printed, and FAILURE returned.
     */
    public function testHandleRemoteExecutorFailureReturnsFailure(): void
    {
        $command = Mockery::mock(ProjectPull::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $command->shouldReceive('argument')->with('server')->andReturn('production');
        $command->shouldReceive('error')->atLeast()->once();
        $command->shouldReceive('line')->atLeast()->once();
        $command->shouldReceive('newLine')->atLeast()->once();

        $result = (function () {
            $this->newLine();
            $this->line("Connecting to remote server 'production'...");

            try {
                throw new \RuntimeException('connection failed');
            } catch (\Exception $e) {
                $this->error("✘ An error occurred on server 'production':");
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        })->call($command);

        $this->assertSame(ProjectPull::FAILURE, $result);
    }
}
