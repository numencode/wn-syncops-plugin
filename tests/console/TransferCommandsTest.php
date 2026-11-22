<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Tests\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use NumenCode\SyncOps\Classes\CommandResult;
use NumenCode\SyncOps\Tests\SyncOpsTestCase;

/**
 * db-pull, db-push, media-pull, media-backup and project-backup.
 */
class TransferCommandsTest extends SyncOpsTestCase
{
    /**
     * Folder in storage/app used by the media tests (removed afterwards).
     */
    protected const MEDIA = 'syncops-tests';

    public function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/' . self::MEDIA));
        File::deleteDirectory(storage_path('framework/testing')); // Storage::fake()

        parent::tearDown();
    }

    public function testDbPullNeedsConfirmationToReplaceTheLocalDatabase(): void
    {
        config(['database.connections.mysql_test' => ['driver' => 'mysql', 'database' => 'local_db', 'username' => 'root', 'password' => '']]);
        config(['database.default' => 'mysql_test']);

        [$code, $output] = $this->runCommand('syncops:db-pull', ['test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('add --force', $output);
        $this->assertSame([], $this->server->commands, 'Nothing ran on the server');
    }

    public function testDbPullCanOnlyDownloadTheDump(): void
    {
        $this->server->results['/command -v/'] = new CommandResult(0, "/usr/bin/mariadb-dump\n");
        $this->server->files['/tmp/syncops.test/dump.sql.gz'] = gzencode('-- dump');

        [$code, $output] = $this->runCommand('syncops:db-pull', ['test', '--no-import', '--tables=users, posts', '--timestamp=Ymd']);

        $this->assertSame(0, $code, $output);
        $file = config('syncops.backup_path') . DIRECTORY_SEPARATOR . 'db-pull' . DIRECTORY_SEPARATOR . now()->format('Ymd') . '_test_remote_db.sql.gz';
        $this->assertFileExists($file);
        $this->assertSame('-- dump', gzdecode((string) file_get_contents($file)));
        $this->assertTrue($this->server->ran("/'remote_db' 'users' 'posts' \\|/"), '--tables');
        $this->assertSame(['/tmp/syncops.test'], $this->server->removed, 'The remote temporary directory is removed');
    }

    public function testDbPushNeedsConfirmationToReplaceTheServerDatabase(): void
    {
        $file = $this->dumpFile('push.sql.gz', gzencode('-- local dump'));

        [$code, $output] = $this->runCommand('syncops:db-push', ['test', '--file=' . $file]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('add --force', $output);
        $this->assertSame([], $this->server->commands, 'Nothing ran on the server');
    }

    public function testDbPushBacksUpTheServerDatabaseThenImportsTheDump(): void
    {
        $this->server->results['/command -v mariadb-dump/'] = new CommandResult(0, "/usr/bin/mariadb-dump\n");
        $this->server->results['/command -v mariadb /'] = new CommandResult(0, "/usr/bin/mariadb\n");
        $this->server->files['/tmp/syncops.test/dump.sql.gz'] = gzencode('-- server dump');
        $file = $this->dumpFile('push.sql.gz', gzencode('-- local dump'));

        [$code, $output] = $this->runCommand('syncops:db-push', ['test', '--file=' . $file, '--force', '--timestamp=Ymd']);

        $this->assertSame(0, $code, $output);

        // The server's database is backed up locally first, and the output says how to restore it
        $backup = config('syncops.backup_path') . DIRECTORY_SEPARATOR . 'db-push' . DIRECTORY_SEPARATOR . now()->format('Ymd') . '_test_remote_db.sql.gz';
        $this->assertFileExists($backup);
        $this->assertSame('-- server dump', gzdecode((string) file_get_contents($backup)));
        $this->assertStringContainsString('syncops:db-push test --file=', $output);

        // Then the dump is uploaded and imported (password only in the option file), then the cache is cleared
        $this->assertSame(gzencode('-- local dump'), $this->server->written['/tmp/syncops.test/push.sql.gz']);
        $import = $this->server->position("/gunzip -c '\\/tmp\\/syncops.test\\/push.sql.gz' \\| sed .* \\| '\\/usr\\/bin\\/mariadb' '--defaults-extra-file=\\/tmp\\/syncops.test\\/client.cnf' 'remote_db'/");
        $this->assertNotNull($import, 'Import command');
        $this->assertGreaterThan($this->server->position('/mariadb-dump/'), $import, 'Backup before the import');
        $this->assertGreaterThan($import, $this->server->position("/'artisan' 'cache:clear'/"));
        $this->assertStringNotContainsString('Pa$$', implode("\n", $this->server->commands));
        $this->assertContains('/tmp/syncops.test', $this->server->removed, 'The remote temporary directory is removed');
    }

    public function testDbPushWithoutBackupPushesAPlainSqlFile(): void
    {
        $file = $this->dumpFile('push.sql', '-- plain');

        [$code, $output] = $this->runCommand('syncops:db-push', ['test', '--file=' . $file, '--force', '--no-backup']);

        $this->assertSame(0, $code, $output);
        $this->assertFalse($this->server->ran('/mysqldump|mariadb-dump/'), 'No backup');
        $this->assertTrue($this->server->ran("/^set -o pipefail; cat '\\/tmp\\/syncops.test\\/push.sql' \\|/"));
    }

    public function testDbPushRejectsTablesWithAFileAndMissingFiles(): void
    {
        $file = $this->dumpFile('push.sql', '-- plain');

        [$code, $output] = $this->runCommand('syncops:db-push', ['test', '--file=' . $file, '--tables=users', '--force']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("--tables can't be combined with --file", $output);

        [$code, $output] = $this->runCommand('syncops:db-push', ['test', '--file=' . $file . '.missing', '--force']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("doesn't exist", $output);
        $this->assertSame([], $this->server->commands, 'Nothing ran on the server');
    }

    public function testMediaPullDownloadsNewAndChangedFilesOnly(): void
    {
        $dir = storage_path('app/' . self::MEDIA);
        File::makeDirectory($dir, 0755, true, true);
        file_put_contents($dir . '/same.jpg', '12345');
        file_put_contents($dir . '/changed.jpg', '1');

        $this->server->listing = [
            self::MEDIA . '/same.jpg'            => 5,
            self::MEDIA . '/changed.jpg'         => 3,
            self::MEDIA . '/new/photo.jpg'       => 3,
            self::MEDIA . '/resized/small.jpg'   => 3,
            self::MEDIA . '/.gitignore'          => 3,
        ];

        foreach (['changed.jpg', 'new/photo.jpg'] as $path) {
            $this->server->files['/srv/project/storage/app/' . self::MEDIA . '/' . $path] = 'abc';
        }

        [$code, $output] = $this->runCommand('syncops:media-pull', ['test', '--dry-run']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('2 file(s) to download', $output);
        $this->assertFileDoesNotExist($dir . '/new/photo.jpg');

        [$code, $output] = $this->runCommand('syncops:media-pull', ['test']);
        $this->assertSame(0, $code, $output);
        $this->assertSame('abc', file_get_contents($dir . '/changed.jpg'));
        $this->assertSame('abc', file_get_contents($dir . '/new/photo.jpg'));
        $this->assertSame('12345', file_get_contents($dir . '/same.jpg'));
        $this->assertFileDoesNotExist($dir . '/resized/small.jpg', 'Excluded folder');
        $this->assertFileDoesNotExist($dir . '/.gitignore', 'Dotfiles are skipped');

        [, $output] = $this->runCommand('syncops:media-pull', ['test', '--no-overwrite']);
        $this->assertStringContainsString('0 file(s) to download', $output);
    }

    public function testMediaBackupUploadsNewAndChangedFilesOnly(): void
    {
        Storage::fake('cloud');
        $dir = storage_path('app/' . self::MEDIA);
        File::makeDirectory($dir . '/resized', 0755, true, true);
        file_put_contents($dir . '/a.jpg', 'aaa');
        file_put_contents($dir . '/b.jpg', 'bbb');
        file_put_contents($dir . '/resized/r.jpg', 'rrr');
        file_put_contents($dir . '/.hidden', 'x');

        Storage::disk('cloud')->put('backup/' . self::MEDIA . '/a.jpg', 'aaa');
        Storage::disk('cloud')->put('backup/' . self::MEDIA . '/b.jpg', 'old version');

        [$code, $output] = $this->runCommand('syncops:media-backup', ['cloud', '--folder=backup', '--log']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Uploaded ' . self::MEDIA . '/b.jpg', $output);
        $this->assertStringNotContainsString('Uploaded ' . self::MEDIA . '/a.jpg', $output, 'Unchanged');
        $this->assertSame('bbb', Storage::disk('cloud')->get('backup/' . self::MEDIA . '/b.jpg'));
        $this->assertFalse(Storage::disk('cloud')->exists('backup/' . self::MEDIA . '/resized/r.jpg'));
        $this->assertFalse(Storage::disk('cloud')->exists('backup/' . self::MEDIA . '/.hidden'));
    }

    public function testBackupCommandsNeedAConfiguredDisk(): void
    {
        [$code, $output] = $this->runCommand('syncops:media-backup', ['nope']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("The storage disk 'nope' is not configured", $output);
    }

    public function testProjectBackupExcludesEnvAndRotatesOldArchives(): void
    {
        Storage::fake('cloud');
        Storage::disk('cloud')->put('project/2000-01-01_00_00_00.tar.gz', 'old');
        Storage::disk('cloud')->put('project/2000-01-02_00_00_00.tar.gz', 'older');
        Storage::disk('cloud')->put('project/notes.txt', 'not a backup');
        touch(storage_path('framework/cache/.syncops-test'));

        [$code, $output] = $this->runCommand('syncops:project-backup', ['cloud', '--keep=2', '--no-delete', '--exclude=plugins,modules,themes']);

        $this->assertSame(0, $code, $output);

        $remaining = Storage::disk('cloud')->files('project');
        $this->assertCount(3, $remaining, implode(', ', $remaining));
        $this->assertContains('project/notes.txt', $remaining, 'Other files are not rotated');
        $this->assertNotContains('project/2000-01-01_00_00_00.tar.gz', $remaining, 'The oldest backup is deleted');

        $archive = glob(config('syncops.backup_path') . '/project/*.tar.gz')[0] ?? null;
        $this->assertNotNull($archive, 'The local archive is kept (--no-delete)');

        $entries = $this->tarEntries($archive);

        $this->assertContains('artisan', $entries, 'Archive entries: ' . count($entries));
        $this->assertContains('.env.example', $entries);
        $this->assertNotContains('.env', $entries);
        $this->assertEmpty(preg_grep('#^(vendor|plugins|storage/framework/cache)/#', $entries), 'Excluded paths');

        @unlink(storage_path('framework/cache/.syncops-test'));
    }

    /**
     * A local dump file for db-push --file (in the test folder, removed afterwards).
     */
    protected function dumpFile(string $name, string $contents): string
    {
        $directory = storage_path('temp/syncops-tests/dumps');
        File::ensureDirectoryExists($directory);
        File::put($directory . '/' . $name, $contents);

        return $directory . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Entry names of a .tar.gz archive (read in PHP, without "./"), from the 512-byte tar headers.
     *
     * @return array<int, string>
     */
    protected function tarEntries(string $archive): array
    {
        $tar = (string) gzdecode((string) file_get_contents($archive));
        $entries = [];
        $longName = null;

        for ($offset = 0; $offset + 512 <= strlen($tar);) {
            $header = substr($tar, $offset, 512);

            if (trim($header, "\0") === '') {
                break;
            }

            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $type = $header[156];
            $data = substr($tar, $offset + 512, $size);
            $offset += 512 + (int) ceil($size / 512) * 512;

            if ($type === 'L') {
                // GNU long name: the name of the next entry
                $longName = rtrim($data, "\0");
                continue;
            }

            if ($type === 'x' || $type === 'g') {
                // pax headers (e.g. from bsdtar)
                if (preg_match('/\d+ path=([^\n]+)\n/', $data, $match)) {
                    $longName = $match[1];
                }

                continue;
            }

            $path = $longName ?? ($prefix !== '' ? $prefix . '/' . $name : $name);
            $longName = null;
            $entries[] = preg_replace('#^\./#', '', rtrim($path, '/'));
        }

        return $entries;
    }
}
