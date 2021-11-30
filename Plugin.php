<?php namespace NumenCode\Backup;

use System\Classes\PluginBase;
use NumenCode\Backup\Console\BackupUpCommand;
use NumenCode\Backup\Console\DbPullCommand;
use NumenCode\Backup\Console\DbBackupCommand;
use NumenCode\Backup\Console\MediaPullCommand;
use NumenCode\Backup\Console\MediaBackupCommand;
use NumenCode\Backup\Console\ProjectPullCommand;
use NumenCode\Backup\Console\ProjectBackupCommand;
use NumenCode\Backup\Console\ProjectCommitCommand;
use NumenCode\Backup\Console\ProjectDeployCommand;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'numencode.backup::lang.plugin.name',
            'description' => 'numencode.backup::lang.plugin.description',
            'author'      => 'Blaz Orazem',
            'icon'        => 'icon-cloud-upload',
            'homepage'    => 'https://github.com/numencode/wn-backup-plugin',
        ];
    }

    public function register()
    {
        $this->registerConsoleCommands();
    }

    protected function registerConsoleCommands()
    {
        $this->registerConsoleCommand('numencode.backup_up', BackupUpCommand::class);
        $this->registerConsoleCommand('numencode.db_pull', DbPullCommand::class);
        $this->registerConsoleCommand('numencode.db_backup', DbBackupCommand::class);
        $this->registerConsoleCommand('numencode.media_pull', MediaPullCommand::class);
        $this->registerConsoleCommand('numencode.media_backup', MediaBackupCommand::class);
        $this->registerConsoleCommand('numencode.project_pull', ProjectPullCommand::class);
        $this->registerConsoleCommand('numencode.project_backup', ProjectBackupCommand::class);
        $this->registerConsoleCommand('numencode.project_commit', ProjectCommitCommand::class);
        $this->registerConsoleCommand('numencode.project_deploy', ProjectDeployCommand::class);
    }
}
