<?php namespace NumenCode\Backup\Console;

use Illuminate\Console\Command;
use Collective\Remote\RemoteFacade as SSH;
use Collective\Remote\RemoteServiceProvider;

class RemoteCommand extends Command
{
    protected $backup = null;
    protected $server = null;
    protected $connection = null;

    protected function sshConnect()
    {
        app()->register(RemoteServiceProvider::class);

        $this->connection = SSH::into($this->argument('server'));
        $this->server = config('remote.connections.' . $this->argument('server'));
        $this->backup = config('remote.connections.' . $this->argument('server') . '.backup');

        return true;
    }

    protected function sshRun(array $commands, $print = false, $path = null)
    {
        if (!isset($this->backup['path'])) {
            $this->error('Project path for [' . $this->argument('server') . '] is undefined!');

            return false;
        }

        $lines = [];
        $commands = array_merge(['cd ' . $this->backup['path'] . $path], $commands);

        $this->connection->run($commands, function ($line) use ($print, &$lines) {
            $lines[] = $line;

            if ($print) {
                echo($line);
            }
        });

        return implode("\n", $lines);
    }

    protected function sshRunAndPrint(array $commands, $path = null)
    {
        return $this->sshRun($commands, true, $path);
    }

    protected function checkForChanges($deploy = false)
    {
        $result = $this->sshRun(['git status']);

        if (str_contains($result, 'nothing to commit')) {
            return true;
        }

        if ($deploy) {
            $this->sshRunAndPrint(['git status']);
            $this->error('Remote changes detected. Aborting deployment process.');
            $this->info('Please run a command: php artisan project:pull ' . $this->argument('server'));
        }

        return false;
    }
}
