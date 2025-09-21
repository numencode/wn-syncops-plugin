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
     * Test running a successful shell command.
     */
    public function testRunLocalCommandSuccess(): void
    {
        $output = $this->runner->runLocalCommand('echo "Hello World"');

        // Assert the command output is as expected
        $this->assertStringContainsString('Hello World', $output);
    }

    /**
     * Test function: runLocalCommand
     * Test running a failing shell command throws an exception.
     */
    public function testRunLocalCommandFailure(): void
    {
        $this->expectException(ProcessFailedException::class);

        // Run a guaranteed invalid command
        $this->runner->runLocalCommand('nonexistent_command_12345');
    }

    /**
     * Test function: runLocalCommand
     * Test that a custom timeout value can be set.
     */
    public function testRunLocalCommandWithCustomTimeout(): void
    {
        $output = $this->runner->runLocalCommand('echo "Timeout Test"', 5);

        // Assert the command output is as expected
        $this->assertStringContainsString('Timeout Test', $output);
    }

    /**
     * Test function: runLocalCommand
     * Test that a process exceeding the timeout throws an exception.
     */
    public function testRunLocalCommandTimeout(): void
    {
        $this->expectException(ProcessTimedOutException::class);

        // Run with timeout shorter than sleep duration
        $this->runner->runLocalCommand('sleep 2', 1);
    }
}

/**
 * Helper class to expose the protected runLocalCommand method.
 */
class RunsLocalCommandsTestHelper
{
    use RunsLocalCommands {
        runLocalCommand as traitRunLocalCommand;
    }

    public function runLocalCommand(string $command, int $timeout = 60): string
    {
        return $this->traitRunLocalCommand($command, $timeout);
    }
}
