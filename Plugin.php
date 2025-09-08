<?php namespace NumenCode\SyncOps;

use System\Classes\PluginBase;
use NumenCode\SyncOps\Console\DbPull;
use NumenCode\SyncOps\Console\DbPush;
use NumenCode\SyncOps\Console\MediaPush;
use NumenCode\SyncOps\Console\ProjectPull;
use NumenCode\SyncOps\Console\ProjectPush;
use NumenCode\SyncOps\Console\MediaPullCommand;
use NumenCode\SyncOps\Console\ProjectBackupCommand;
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
        $this->registerConsoleCommand('syncops.db_pull', DbPull::class);
        $this->registerConsoleCommand('syncops.db_push', DbPush::class);
//        $this->registerConsoleCommand('syncops.media_pull', MediaPullCommand::class);
        $this->registerConsoleCommand('syncops.media_push', MediaPush::class);
        $this->registerConsoleCommand('syncops.project_pull', ProjectPull::class);
        $this->registerConsoleCommand('syncops.project_push', ProjectPush::class);
//        $this->registerConsoleCommand('syncops.project_deploy', ProjectDeployCommand::class);
//        $this->registerConsoleCommand('syncops.project_backup', ProjectBackupCommand::class);
    }
}
