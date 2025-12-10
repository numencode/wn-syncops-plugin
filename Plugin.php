<?php namespace NumenCode\SyncOps;

use System\Classes\PluginBase;
use NumenCode\SyncOps\Console\DbPull;
use NumenCode\SyncOps\Console\DbPush;
use NumenCode\SyncOps\Console\Validate;
use NumenCode\SyncOps\Console\MediaPull;
use NumenCode\SyncOps\Console\MediaPush;
use NumenCode\SyncOps\Console\ProjectPull;
use NumenCode\SyncOps\Console\ProjectPush;
use NumenCode\SyncOps\Console\ProjectBackup;
use NumenCode\SyncOps\Console\ProjectDeploy;
use NumenCode\SyncOps\Console\RemoteArtisan;

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

        $this->registerHelpers();
        $this->registerConsoleCommands();
    }

    protected function registerHelpers()
    {
        require_once __DIR__ . '/helpers.php';
    }

    protected function registerConsoleCommands()
    {
        $this->registerConsoleCommand('syncops.db_pull', DbPull::class);
        $this->registerConsoleCommand('syncops.db_push', DbPush::class);
        $this->registerConsoleCommand('syncops.media_pull', MediaPull::class);
        $this->registerConsoleCommand('syncops.media_push', MediaPush::class);
        $this->registerConsoleCommand('syncops.project_backup', ProjectBackup::class);
        $this->registerConsoleCommand('syncops.project_deploy', ProjectDeploy::class);
        $this->registerConsoleCommand('syncops.project_pull', ProjectPull::class);
        $this->registerConsoleCommand('syncops.project_push', ProjectPush::class);
        $this->registerConsoleCommand('syncops.remote_artisan', RemoteArtisan::class);
        $this->registerConsoleCommand('syncops.validate', Validate::class);
    }
}
