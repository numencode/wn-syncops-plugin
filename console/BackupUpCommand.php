<?php namespace NumenCode\Backup\Console;

use File;
use Illuminate\Console\Command;

class BackupUpCommand extends Command
{
    protected $signature = 'backup:up';

    protected $description = 'Builds Backup plugin for Winter';

    public function handle()
    {
        File::copy(__DIR__ . '/../config/remote.php', config_path('remote.php'));

        $this->alert('New configuration file "/config/remote.php" created successfully.');
    }
}
