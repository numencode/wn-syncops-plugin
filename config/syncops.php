<?php

/*
 * SyncOps configuration. Publish it to config/syncops.php with:
 *
 *     php artisan vendor:publish --tag=syncops-config
 *
 * Keep credentials in the .env file. Every key except the SSH host, username, a key or password and the
 * project path is optional.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Timestamp format
    |--------------------------------------------------------------------------
    |
    | Used for the names of database dumps and project archives (PHP date format).
    |
    */

    'timestamp' => 'Y-m-d_H_i_s',

    /*
    |--------------------------------------------------------------------------
    | Local backups
    |--------------------------------------------------------------------------
    |
    | "backup_path": where kept dumps and archives are stored locally (the --folder option adds a subfolder).
    | Keep it outside storage/app, which media-pull and media-backup synchronize.
    |
    | "backup_keep": how many backups db-backup and project-backup keep in their target folder (locally and
    | in the cloud); older ones are deleted. Empty keeps all. The --keep option overrides it.
    |
    */

    'backup_path' => storage_path('backups'),

    'backup_keep' => env('SYNCOPS_BACKUP_KEEP'),

    /*
    |--------------------------------------------------------------------------
    | Media synchronization
    |--------------------------------------------------------------------------
    |
    | Paths in storage/app that media-pull and media-backup skip: folder names anywhere in the path. Files
    | starting with a dot (.gitignore, ...) are always skipped.
    |
    */

    'media' => [
        'exclude' => ['resized', 'thumb', 'temp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Project backup
    |--------------------------------------------------------------------------
    |
    | Paths (relative to the project root) that project-backup leaves out of the archive. The .env file is
    | left out unless the --include-env option is given. The --exclude option adds more paths.
    |
    */

    'backup' => [
        'exclude' => [
            'vendor',
            'node_modules',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/temp',
            'storage/backups',
            'storage/app/resized',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Local database clients
    |--------------------------------------------------------------------------
    |
    | The mysql and mysqldump (or mariadb, mariadb-dump) binaries on this machine, used by db-pull and
    | db-backup. By default they are taken from the PATH.
    |
    */

    'local' => [
        'mysql'     => env('SYNCOPS_MYSQL', 'mysql'),
        'mysqldump' => env('SYNCOPS_MYSQLDUMP', 'mysqldump'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote servers
    |--------------------------------------------------------------------------
    |
    | One entry per server, named e.g. "production" or "staging"; the name is the {server} argument of the
    | commands.
    |
    */

    'connections' => [
        'production' => [
            /*
             * SSH connection. Use a private key (recommended) or a password.
             *
             * "host_fingerprint" pins the server's host key (format "SHA256:…", as shown by
             * `ssh-keygen -lf` or `php artisan syncops:validate --connect`). When it is set, SyncOps refuses
             * to connect to a server with a different key.
             *
             * "timeout" limits each remote command in seconds (0 = no limit).
             */
            'ssh' => [
                'host'             => env('SYNCOPS_PRODUCTION_HOST'),
                'port'             => env('SYNCOPS_PRODUCTION_PORT', 22),
                'username'         => env('SYNCOPS_PRODUCTION_USERNAME'),
                'password'         => env('SYNCOPS_PRODUCTION_PASSWORD'),
                'key_path'         => env('SYNCOPS_PRODUCTION_KEY'),
                'key_passphrase'   => env('SYNCOPS_PRODUCTION_KEY_PASSPHRASE'),
                'host_fingerprint' => env('SYNCOPS_PRODUCTION_HOST_FINGERPRINT'),
                'timeout'          => env('SYNCOPS_PRODUCTION_TIMEOUT', 0),
            ],

            /*
             * The project on the server.
             *
             * "branch_prod" is the branch checked out on the server, "branch_main" the branch that
             * project-deploy merges into it (set it to false to deploy with "git pull" instead).
             *
             * "php" and "composer" are the binaries used on the server, e.g. "php8.4" and
             * "php8.4 /usr/bin/composer" when the server's default PHP is a different version.
             *
             * "temp_path" is where temporary files (database dumps) are created; default the system's
             * temporary directory.
             */
            'project' => [
                'path'        => env('SYNCOPS_PRODUCTION_PATH'),
                'branch_prod' => env('SYNCOPS_PRODUCTION_BRANCH_PROD', 'prod'),
                'branch_main' => env('SYNCOPS_PRODUCTION_BRANCH_MAIN', 'main'),
                'php'         => env('SYNCOPS_PRODUCTION_PHP', 'php'),
                'composer'    => env('SYNCOPS_PRODUCTION_COMPOSER', 'composer'),
                'temp_path'   => env('SYNCOPS_PRODUCTION_TEMP_PATH'),
            ],

            /*
             * Optional file ownership after a deployment, e.g. "www-data:www-data" for the folders the web
             * server writes to. Needs sudo without a password when the SSH user is not the owner.
             */
            'permissions' => [
                'root_user'   => env('SYNCOPS_PRODUCTION_ROOT_USER'),
                'web_user'    => env('SYNCOPS_PRODUCTION_WEB_USER'),
                'web_folders' => ['storage', 'themes'],
            ],

            /*
             * The server's database, for db-pull and remote-health. "tables" limits db-pull to these tables
             * (all tables when empty; the --tables option overrides it).
             */
            'database' => [
                'database' => env('SYNCOPS_PRODUCTION_DB_DATABASE'),
                'username' => env('SYNCOPS_PRODUCTION_DB_USERNAME'),
                'password' => env('SYNCOPS_PRODUCTION_DB_PASSWORD'),
                'host'     => env('SYNCOPS_PRODUCTION_DB_HOST'),
                'port'     => env('SYNCOPS_PRODUCTION_DB_PORT'),
                'tables'   => [],
            ],
        ],
    ],
];
