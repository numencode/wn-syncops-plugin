<?php namespace NumenCode\SyncOps\Tests\Traits;

use PluginTestCase;
use NumenCode\SyncOps\Traits\RunsLocalCommands;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class RunsLocalCommandsTest extends PluginTestCase
{
    protected RunsLocalCommandsTestHelper $runner;

    public function setUp(): void
    {
        parent::setUp();
        $this->runner = new RunsLocalCommandsTestHelper();
    }

    /**
     * Test function: runLocalCommand
     * Test running a successful shell command cross-platform.
     */
    public function testRunLocalCommandSuccess(): void
    {
        $cmd = strtoupper(PHP_OS_FAMILY) === 'WINDOWS'
            ? 'echo HelloWorld'
            : 'echo "HelloWorld"';

        $output = $this->runner->runLocalCommand($cmd);

        $this->assertStringContainsString('HelloWorld', $output);
    }

    /**
     * Test function: runLocalCommand
     * Test running a failing shell command throws an exception.
     */
    public function testRunLocalCommandFailure(): void
    {
        $this->expectException(ProcessFailedException::class);
        $this->runner->runLocalCommand('nonexistent_command_12345');
    }

    /**
     * Test function: runLocalCommand
     * Ensure error output is captured in ProcessFailedException.
     */
    public function testRunLocalCommandFailureIncludesErrorOutput(): void
    {
        try {
            $this->runner->runLocalCommand('invalid_command_zzz');
            $this->fail('Expected ProcessFailedException was not thrown.');
        } catch (ProcessFailedException $e) {
            $this->assertStringContainsString('invalid_command_zzz', $e->getMessage());
        }
    }

    /**
     * Test function: runLocalCommand
     * Test that a custom timeout value can be set.
     */
    public function testRunLocalCommandWithCustomTimeout(): void
    {
        $cmd = strtoupper(PHP_OS_FAMILY) === 'WINDOWS'
            ? 'echo TimeoutTest'
            : 'echo "TimeoutTest"';

        $output = $this->runner->runLocalCommand($cmd, 5);
        $this->assertStringContainsString('TimeoutTest', $output);
    }

    /**
     * Test function: runLocalCommand
     * Test that a process exceeding the timeout throws an exception and stops early.
     */
    public function testRunLocalCommandTimeoutStopsEarly(): void
    {
        $this->expectException(ProcessTimedOutException::class);

        $command = strtoupper(PHP_OS_FAMILY) === 'WINDOWS'
            ? 'ping 127.0.0.1 -n 4 > NUL'
            : 'sleep 3';

        $start = microtime(true);

        try {
            $this->runner->runLocalCommand($command, 1);
        } finally {
            $elapsed = microtime(true) - $start;
            $this->assertLessThan(2, $elapsed, 'Process should stop around timeout threshold');
        }
    }
}

/**
 * Helper class to expose the protected runLocalCommand method.
 */
class RunsLocalCommandsTestHelper
{
    use RunsLocalCommands {
        runLocalCommand as public;
    }
}
