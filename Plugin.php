<?php namespace NumenCode\SyncOps;

use System\Classes\PluginBase;
use NumenCode\SyncOps\Console\DbPullCommand;
use NumenCode\SyncOps\Console\DbBackupCommand;
use NumenCode\SyncOps\Console\MediaPullCommand;
use NumenCode\SyncOps\Console\MediaBackupCommand;
use NumenCode\SyncOps\Console\ProjectPullCommand;
use NumenCode\SyncOps\Console\ProjectBackupCommand;
use NumenCode\SyncOps\Console\ProjectCommitCommand;
use NumenCode\SyncOps\Console\ProjectDeployCommand;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'numencode.syncops::lang.plugin.name',
            'description' => 'numencode.syncops::lang.plugin.description',
            'author'      => 'Blaz Orazem',
            'icon'        => 'icon-cloud-upload',
            'homepage'    => 'https://github.com/numencode/wn-syncops-plugin',
        ];
    }

    public function boot()
    {
        $this->publishes([__DIR__ . '/config/syncops.php' => config_path('syncops.php')], 'syncops-config');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/config/syncops.php', 'syncops');

        $this->registerConsoleCommands();
    }

    protected function registerConsoleCommands()
    {
//        $this->registerConsoleCommand('syncops.db_pull', DbPullCommand::class);
//        $this->registerConsoleCommand('syncops.db_backup', DbBackupCommand::class);
        $this->registerConsoleCommand('syncops.media_pull', MediaPullCommand::class);
        $this->registerConsoleCommand('syncops.media_backup', MediaBackupCommand::class);
        $this->registerConsoleCommand('syncops.project_pull', ProjectPullCommand::class);
//        $this->registerConsoleCommand('syncops.project_backup', ProjectBackupCommand::class);
//        $this->registerConsoleCommand('syncops.project_commit', ProjectCommitCommand::class);
        $this->registerConsoleCommand('syncops.project_deploy', ProjectDeployCommand::class);
    }
}
