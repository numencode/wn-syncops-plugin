<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Tests\Classes;

use NumenCode\SyncOps\Classes\CommandResult;
use NumenCode\SyncOps\Classes\ConnectionConfig;
use NumenCode\SyncOps\Classes\Redactor;
use NumenCode\SyncOps\Classes\RemoteServer;
use NumenCode\SyncOps\Tests\SyncOpsTestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;

class CoreClassesTest extends SyncOpsTestCase
{
    public function testRedactorHidesSecretsInAllForms(): void
    {
        $redactor = new Redactor(['pa$$', '', null, "it's"]);

        $this->assertSame('a=*** b=*** c=*** d=***', $redactor->redact("a=pa$$ b='pa$$' c=pa%24%24 d='it'\\''s'"));
        $this->assertSame('nothing secret', $redactor->redact('nothing secret'));
        $this->assertSame('x', (new Redactor())->redact('x'));
    }

    public function testCommandResultErrorsNameTheStepButNotTheCommand(): void
    {
        $this->assertSame('ok', (new CommandResult(0, 'ok'))->throwIfFailed('Step')->output);

        try {
            (new CommandResult(2, 'stdout', "Access denied\n"))->throwIfFailed('Dumping the database');
            $this->fail('A failed command must throw');
        } catch (RuntimeException $e) {
            $this->assertSame("Dumping the database failed (exit code 2):\nAccess denied", $e->getMessage());
        }
    }

    public function testQuoteIsPosixOnEveryPlatform(): void
    {
        $this->assertSame("'plain'", RemoteServer::quote('plain'));
        $this->assertSame("'it'\\''s \"x\" 100% !'", RemoteServer::quote('it\'s "x" 100% !'));
        $this->assertSame("'git' 'commit' '-m' 'a b'", RemoteServer::escape(['git', 'commit', '-m', 'a b']));
    }

    public function testQuotedArgumentsSurviveARealShell(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || !is_executable('/bin/sh')) {
            $this->markTestSkipped('Needs a POSIX shell.');
        }

        $value = "it's \"x\" 100% ! \$HOME `id` \\ ;|&";
        $process = Process::fromShellCommandline('printf %s ' . RemoteServer::quote($value));
        $process->run();

        $this->assertSame($value, $process->getOutput());
    }

    public function testSplitsConfiguredBinaries(): void
    {
        $this->assertSame(['php8.4', '/usr/bin/composer'], RemoteServer::split(' php8.4   /usr/bin/composer '));
        $this->assertSame(['php'], RemoteServer::split('php'));
    }

    public function testFingerprintIsTheOpenSshSha256Format(): void
    {
        $blob = random_bytes(51);
        $expected = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');

        $this->assertSame($expected, RemoteServer::fingerprintOf('ssh-ed25519 ' . base64_encode($blob)));
        $this->assertStringNotContainsString('=', $expected);
    }

    public function testCdHandlesTildePaths(): void
    {
        $cd = function (string $path): string {
            $this->useConnection(['project' => ['path' => $path]]);
            $server = new RemoteServer(ConnectionConfig::fromConfig('test'));

            return (new ReflectionMethod($server, 'cdProject'))->invoke($server);
        };

        $this->assertSame("cd '/srv/my project'", $cd('/srv/my project/'));
        $this->assertSame('cd "$HOME"', $cd('~'));
        $this->assertSame('cd "$HOME"/\'web/site\'', $cd('~/web/site'));
    }

    public function testListingIsFlattenedToFilesWithSizes(): void
    {
        $file = static fn (int $size, int $type = 1) => (object) ['type' => $type, 'size' => $size];
        $listing = [
            '.'          => $file(0, 2),
            '..'         => $file(0, 2),
            '.gitignore' => $file(14),
            'media'      => [
                '.'       => $file(0, 2),
                'a.jpg'   => $file(100),
                'link'    => $file(0, 3),
                'sub'     => ['.' => $file(0, 2), 'b.png' => $file(200)],
            ],
        ];

        $files = [];
        (new ReflectionMethod(RemoteServer::class, 'flattenListing'))->invokeArgs(null, [$listing, '', &$files]);
        ksort($files);

        $this->assertSame(['.gitignore' => 14, 'media/a.jpg' => 100, 'media/sub/b.png' => 200], $files);
    }
}
