<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Tests\Console;

use NumenCode\SyncOps\Classes\CommandResult;
use NumenCode\SyncOps\Tests\SyncOpsTestCase;

class ProjectDeployTest extends SyncOpsTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->server->results["/'rev-parse' '--abbrev-ref' 'HEAD'/"] = new CommandResult(0, "prod\n");
        $this->server->results["/'rev-parse' 'HEAD'/"] = $this->commits('aaaaaaa', 'bbbbbbb');
        $this->server->results["/'diff' '--name-only' 'aaaaaaa' 'bbbbbbb'/"] = new CommandResult(0, "themes/demo/pages/home.htm\n");
    }

    /**
     * "git rev-parse HEAD" returns $before the first time and $after afterwards.
     */
    protected function commits(string $before, string $after): callable
    {
        $calls = 0;

        return static function () use (&$calls, $before, $after): CommandResult {
            return new CommandResult(0, ($calls++ === 0 ? $before : $after) . "\n");
        };
    }

    public function testDeploysInTheRightOrder(): void
    {
        [$code, $output] = $this->runCommand('syncops:project-deploy', ['test']);
        $server = $this->server;

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString("Deployed 'test': aaaaaaa → bbbbbbb.", $output);

        $order = array_map(fn ($pattern) => $server->position($pattern), [
            "/'artisan' 'down'/",
            "/'git' 'fetch' 'origin'/",
            "/'git' 'merge' '--no-edit' 'origin\\/main'/",
            "/'git' 'push' 'origin' 'prod'/",
            "/'artisan' 'cache:clear'/",
            "/'artisan' 'up'/",
        ]);

        $this->assertNotContains(null, $order, 'All steps ran: ' . implode("\n", $server->commands));
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'Steps ran in order');
        $this->assertFalse($server->ran('/composer/'), 'composer.lock did not change');
        $this->assertFalse($server->ran('/winter:up/'));
    }

    public function testComposerAndMigrationsRunWhenTheLockFileChanged(): void
    {
        $this->useConnection(['project' => ['php' => 'php8.4', 'composer' => 'php8.4 /usr/bin/composer']]);
        $this->server->results["/'diff' '--name-only' 'aaaaaaa' 'bbbbbbb'/"] = new CommandResult(0, "composer.lock\nplugins/a.php\n");

        [$code, $output] = $this->runCommand('syncops:project-deploy', ['test']);

        $this->assertSame(0, $code, $output);
        $this->assertTrue($this->server->ran("/'php8.4' '\\/usr\\/bin\\/composer' 'install' '--no-dev' '--no-interaction' '--prefer-dist' '--optimize-autoloader'/"));
        $this->assertTrue($this->server->ran("/'php8.4' 'artisan' 'winter:up' '--no-interaction'/"));
        $this->assertLessThan($this->server->position('/winter:up/'), $this->server->position('/composer/'));
    }

    public function testAFailedMergeIsAbortedAndTheSiteComesBackUp(): void
    {
        $this->server->results["/'git' 'merge' '--no-edit'/"] = new CommandResult(1, "CONFLICT (content): Merge conflict in a.txt\n");

        [$code, $output] = $this->runCommand('syncops:project-deploy', ['test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('the merge was aborted and the server is unchanged', $output);
        $this->assertTrue($this->server->ran("/'git' 'merge' '--abort'/"));
        $this->assertTrue($this->server->ran("/'artisan' 'up'/"), 'Maintenance mode is always ended');
        $this->assertFalse($this->server->ran("/'git' 'push'/"));
    }

    public function testAnyFailingStepStillEndsMaintenanceMode(): void
    {
        $this->server->results["/'cache:clear'/"] = new CommandResult(1, '', 'Cache store is not writable');

        [$code, $output] = $this->runCommand('syncops:project-deploy', ['test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('php artisan cache:clear failed (exit code 1)', $output);
        $this->assertGreaterThan($this->server->position("/'cache:clear'/"), $this->server->position("/'artisan' 'up'/"));
    }

    public function testRefusesOnTheWrongBranchOrWithChanges(): void
    {
        $this->server->results["/'rev-parse' '--abbrev-ref' 'HEAD'/"] = new CommandResult(0, "main\n");
        [$code, $output] = $this->runCommand('syncops:project-deploy', ['test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("on branch 'main', but 'prod' is configured", $output);
        $this->assertFalse($this->server->ran('/artisan/'), 'Nothing was changed');

        $this->server->commands = [];
        $this->server->results["/'rev-parse' '--abbrev-ref' 'HEAD'/"] = new CommandResult(0, "prod\n");
        $this->server->results["/'status' '--porcelain'$/"] = new CommandResult(0, " M themes/demo/pages/home.htm\n");
        [$code, $output] = $this->runCommand('syncops:project-deploy', ['test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('syncops:project-pull test', $output);
        $this->assertFalse($this->server->ran('/artisan/'));
    }

    public function testPullModeAndFastAndSudo(): void
    {
        $this->useConnection(['project' => ['branch_main' => false]]);

        [$code, $output] = $this->runCommand('syncops:project-deploy', ['test', '--fast', '--sudo']);

        $this->assertSame(0, $code, $output);
        $this->assertTrue($this->server->ran("/'git' 'pull' '--no-edit'/"));
        $this->assertFalse($this->server->ran("/'git' 'merge'/"));
        $this->assertFalse($this->server->ran("/'down'/"), '--fast: no maintenance mode');
        $this->assertTrue($this->server->ran("/'sudo' '-n' 'php' 'artisan' 'cache:clear'/"));
    }

    public function testComposerFileModeChangesAreRestored(): void
    {
        $this->server->results["/'diff' '--summary'/"] = new CommandResult(0, " mode change 100644 => 100755 modules/a.js\n mode change 100644 => 100755 modules/b.js\n");
        $this->server->results["/'core.fileMode=false' 'diff'/"] = new CommandResult(0, "modules/b.js\n");

        [$code, $output] = $this->runCommand('syncops:project-deploy', ['test', '--composer']);

        $this->assertSame(0, $code, $output);
        $this->assertTrue($this->server->ran("/'git' 'checkout' '--' 'modules\\/a.js'$/"), 'Mode-only change restored');
        $this->assertFalse($this->server->ran("/'checkout' '--' .*b\\.js/"), 'Content change kept');
        $this->assertStringContainsString('Restored the Git file mode of 1 file(s)', $output);
    }

    public function testDryRunChangesNothing(): void
    {
        $this->server->results["/'HEAD\\.\\.\\.origin\\/main'/"] = new CommandResult(0, "composer.lock\n");

        [$code, $output] = $this->runCommand('syncops:project-deploy', ['test', '--dry-run']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('1 file(s) incoming from origin/main', $output);
        $this->assertStringContainsString('composer install --no-dev', $output);
        $this->assertStringNotContainsString('(skipped: composer.lock unchanged)', $output);

        foreach (['down', 'merge', 'push', 'install', 'up'] as $word) {
            $this->assertFalse($this->server->ran("/'{$word}'/"), "Dry run ran '{$word}'");
        }
    }
}
