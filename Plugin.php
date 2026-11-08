<?php

declare(strict_types=1);

namespace NumenCode\SyncOps;

use NumenCode\SyncOps\Console\DbBackup;
use NumenCode\SyncOps\Console\DbPull;
use NumenCode\SyncOps\Console\MediaBackup;
use NumenCode\SyncOps\Console\MediaPull;
use NumenCode\SyncOps\Console\ProjectBackup;
use NumenCode\SyncOps\Console\ProjectCommit;
use NumenCode\SyncOps\Console\ProjectDeploy;
use NumenCode\SyncOps\Console\ProjectPull;
use NumenCode\SyncOps\Console\RemoteArtisan;
use NumenCode\SyncOps\Console\RemoteHealth;
use NumenCode\SyncOps\Console\RemoteMigrate;
use NumenCode\SyncOps\Console\Validate;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    /**
     * Console commands: registration key => class.
     */
    public const COMMANDS = [
        'syncops.db_pull'        => DbPull::class,
        'syncops.db_backup'      => DbBackup::class,
        'syncops.media_pull'     => MediaPull::class,
        'syncops.media_backup'   => MediaBackup::class,
        'syncops.project_backup' => ProjectBackup::class,
        'syncops.project_commit' => ProjectCommit::class,
        'syncops.project_deploy' => ProjectDeploy::class,
        'syncops.project_pull'   => ProjectPull::class,
        'syncops.remote_artisan' => RemoteArtisan::class,
        'syncops.remote_health'  => RemoteHealth::class,
        'syncops.remote_migrate' => RemoteMigrate::class,
        'syncops.validate'       => Validate::class,
    ];

    public function pluginDetails(): array
    {
        return [
            'name'        => 'numencode.syncops::lang.plugin.name',
            'description' => 'numencode.syncops::lang.plugin.description',
            'author'      => 'Blaz Orazem',
            'icon'        => 'icon-cloud-upload',
            'homepage'    => 'https://www.numencode.com',
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/syncops.php', 'syncops');

        foreach (self::COMMANDS as $key => $command) {
            $this->registerConsoleCommand($key, $command);
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/config/syncops.php' => config_path('syncops.php')], 'syncops-config');
    }
}
