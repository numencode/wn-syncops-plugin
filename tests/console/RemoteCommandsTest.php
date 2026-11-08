<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Tests\Console;

use NumenCode\SyncOps\Classes\CommandResult;
use NumenCode\SyncOps\Tests\SyncOpsTestCase;

/**
 * remote-artisan, remote-migrate, remote-health, validate and project-pull.
 */
class RemoteCommandsTest extends SyncOpsTestCase
{
    public function testRemoteArtisanPassesEverythingOn(): void
    {
        $this->useConnection(['project' => ['php' => 'php8.4']]);
        $this->server->results["/'artisan' 'cache:clear'/"] = new CommandResult(0, "Application cache cleared\n");

        [$code, $output] = $this->runCommand('syncops:remote-artisan', ['test', 'cache:clear', '--force', '--tags=a b']);

        $this->assertSame(0, $code, $output);
        $this->assertTrue($this->server->ran("/^'php8\\.4' 'artisan' 'cache:clear' '--force' '--tags=a b' '--no-interaction'$/"), implode("\n", $this->server->commands));
        $this->assertStringContainsString('Application cache cleared', $output);
    }

    public function testRemoteArtisanReturnsTheRemoteExitCode(): void
    {
        $this->server->results['/does:not-exist/'] = new CommandResult(3, "There are no commands defined\n");

        [$code, $output] = $this->runCommand('syncops:remote-artisan', ['test', 'does:not-exist', '-n']);

        $this->assertSame(3, $code);
        $this->assertStringContainsString("failed on 'test' (exit code 3)", $output);
        $this->assertTrue($this->server->ran("/'does:not-exist' '-n'$/"), 'No second --no-interaction after -n');
    }

    public function testRemoteMigrateRunsWinterUp(): void
    {
        [$code] = $this->runCommand('syncops:remote-migrate', ['test']);

        $this->assertSame(0, $code);
        $this->assertTrue($this->server->ran("/^'php' 'artisan' 'winter:up' '--no-interaction'$/"));
    }

    public function testRemoteHealthReportsFailures(): void
    {
        $this->server->results["/'-v'$/"] = new CommandResult(0, "PHP 8.4.1 (cli)\nCopyright\n");
        $this->server->results["/'composer' '--version'/"] = new CommandResult(127, '', 'composer: command not found');

        [$code, $output] = $this->runCommand('syncops:remote-health', ['test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('✔ PHP: PHP 8.4.1 (cli)', $output);
        $this->assertStringContainsString('✘ Composer: composer: command not found', $output);
        $this->assertStringContainsString('Some checks failed', $output);
    }

    public function testValidateChecksAllConnections(): void
    {
        config(['syncops.connections.broken' => ['ssh' => ['host' => 'x'], 'project' => []]]);

        [$code, $output] = $this->runCommand('syncops:validate');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('✔ Configuration is valid.', $output, 'The "test" connection');
        $this->assertStringContainsString("✘ 'ssh.username' is required", $output);
        $this->assertStringContainsString("✘ 'project.path' is required", $output);

        [$code, $output] = $this->runCommand('syncops:validate', ['--server=test']);
        $this->assertSame(0, $code, $output);

        [$code, $output] = $this->runCommand('syncops:validate', ['--server=nope']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("no connection named 'nope'", $output);
    }

    public function testProjectPullCommitsAndPushesTheServersChanges(): void
    {
        $this->server->results["/'status' '--porcelain'/"] = new CommandResult(0, " M themes/demo/pages/home.htm\n?? themes/demo/partials/new.htm\n");
        $this->server->results["/'--abbrev-ref'/"] = new CommandResult(0, "prod\n");

        [$code, $output] = $this->runCommand('syncops:project-pull', ['test', '--no-merge', '--message=Edits on the server']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString("2 changed file(s) on 'test'", $output);
        $this->assertTrue($this->server->ran("/'git' 'add' '--all'/"));
        $this->assertTrue($this->server->ran("/'git' 'commit' '-m' 'Edits on the server'/"));
        $this->assertTrue($this->server->ran("/'git' 'push' 'origin' 'prod'/"));
    }

    public function testProjectPullAbortsAFailedPull(): void
    {
        $this->server->results["/'status' '--porcelain'/"] = new CommandResult(0, " M a.htm\n");
        $this->server->results["/'--abbrev-ref'/"] = new CommandResult(0, "prod\n");
        $this->server->results["/'git' 'pull'/"] = new CommandResult(1, "CONFLICT (content)\n");

        [$code, $output] = $this->runCommand('syncops:project-pull', ['test', '--pull', '--no-merge']);

        $this->assertSame(1, $code);
        $this->assertTrue($this->server->ran("/'git' 'merge' '--abort'/"));
        $this->assertFalse($this->server->ran("/'git' 'push'/"));
        $this->assertStringContainsString('the merge was aborted', $output);
    }

    public function testProjectPullWithoutChangesDoesNothing(): void
    {
        [$code, $output] = $this->runCommand('syncops:project-pull', ['test']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("There are no changes on 'test'", $output);
        $this->assertFalse($this->server->ran("/'commit'/"));
    }

    public function testErrorsNeverShowSecrets(): void
    {
        // A remote error that echoes the database password
        $this->server->results['/command -v/'] = new CommandResult(0, "/usr/bin/mysqldump\n");
        $this->server->results['/pipefail/'] = new CommandResult(2, '', "bad option near 'Secret\"Pa\$\$&word'");

        [$code, $output] = $this->runCommand('syncops:db-pull', ['test', '--no-import']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('bad option near ***', $output);
        $this->assertStringNotContainsString('Secret', $output);
    }
}
