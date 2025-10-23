<?php namespace NumenCode\SyncOps\Tests\Console;

use Mockery;
use PluginTestCase;
use NumenCode\SyncOps\Console\ProjectPush;

class ProjectPushTest extends PluginTestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test function: handle
     * When there are no local changes (empty git status), the command should exit successfully
     * and print the appropriate informational message without attempting to add/commit/push.
     */
    public function testHandleNoChangesReturnsSuccess(): void
    {
        $command = Mockery::mock(ProjectPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        // Expect initial status check
        $command->shouldReceive('runLocalCommand')
            ->once()
            ->with('git status --porcelain')
            ->andReturn('');

        // Ensure no other git operations are attempted
        $command->shouldNotReceive('runLocalCommand')->with('git add --all');
        $command->shouldNotReceive('runLocalCommand')->with(Mockery::on(function ($arg) {
            return is_string($arg) && strpos($arg, 'git commit -m') === 0;
        }));
        $command->shouldNotReceive('runLocalCommand')->with('git push');

        // Output expectations (not strictly validating content except key line)
        $command->shouldReceive('newLine')->atLeast()->once();
        $command->shouldReceive('line')->atLeast()->once();
        $command->shouldReceive('info')->once()->with(Mockery::pattern('/No changes to commit/i'));

        $result = $command->handle();

        $this->assertSame(ProjectPush::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * When there are changes, the command should add, commit with provided message, and push, returning SUCCESS.
     */
    public function testHandleWithChangesCommitsAndPushes(): void
    {
        $message = 'My custom commit message';

        $command = Mockery::mock(ProjectPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        // Option should provide custom message
        $command->shouldReceive('option')->with('message')->andReturn($message);

        // Status indicates changes
        $command->shouldReceive('runLocalCommand')
            ->once()
            ->with('git status --porcelain')
            ->andReturn(" M file.txt");

        // Add all
        $command->shouldReceive('runLocalCommand')
            ->once()
            ->with('git add --all')
            ->andReturn('');

        // Commit with exact quoted message
        $command->shouldReceive('runLocalCommand')
            ->once()
            ->with('git commit -m "' . $message . '"')
            ->andReturn('');

        // Push
        $command->shouldReceive('runLocalCommand')
            ->once()
            ->with('git push')
            ->andReturn('');

        // Allow console outputs
        $command->shouldReceive('newLine')->atLeast()->once();
        $command->shouldReceive('line')->atLeast()->once();
        $command->shouldReceive('warn')->atLeast()->once();
        $command->shouldReceive('info')->once()->with(Mockery::pattern('/successfully pushed/i'));

        $result = $command->handle();

        $this->assertSame(ProjectPush::SUCCESS, $result);
    }

    /**
     * Test function: handle
     * If an exception occurs during any git operation (e.g., push),
     * the command should print error messages and return FAILURE.
     */
    public function testHandleProcessFailureReturnsFailure(): void
    {
        $command = Mockery::mock(ProjectPush::class)->makePartial()->shouldAllowMockingProtectedMethods();

        // Simulate detected changes so that it proceeds to add/commit/push
        $command->shouldReceive('runLocalCommand')
            ->once()
            ->with('git status --porcelain')
            ->andReturn('M file');

        $command->shouldReceive('option')->with('message')->andReturnNull(); // use default message

        // Allow add and commit to pass
        $command->shouldReceive('runLocalCommand')->once()->with('git add --all')->andReturn('');
        $command->shouldReceive('runLocalCommand')->once()->with(Mockery::on(function ($arg) {
            return is_string($arg) && strpos($arg, 'git commit -m "Server changes"') === 0;
        }))->andReturn('');

        // Fail on push with a generic exception
        $command->shouldReceive('runLocalCommand')->once()->with('git push')->andThrow(new \RuntimeException('push failed'));

        // Expect error outputs
        $command->shouldReceive('newLine')->atLeast()->once();
        $command->shouldReceive('line')->atLeast()->once();
        $command->shouldReceive('warn')->atLeast()->once();
        $command->shouldReceive('error')->atLeast()->once();

        $result = $command->handle();

        $this->assertSame(ProjectPush::FAILURE, $result);
    }
}
