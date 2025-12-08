<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Timestamp Format
    |--------------------------------------------------------------------------
    |
    | Here you can define the default timestamp format, used for naming files,
    | such as database dump files, archive files, etc.
    |
    */

    'timestamp' => 'Y-m-d_H_i_s',

    /*
    |--------------------------------------------------------------------------
    | Remote Server Connections
    |--------------------------------------------------------------------------
    |
    | Here you can define the remote servers that SyncOps will connect to for
    | deployment, synchronization, and database commands. Each connection is
    | identified by a unique name (e.g. "production", "staging") and contains
    | the SSH and project details required for remote operations.
    |
    */

    'connections' => [
        'production' => [
            /*
            |--------------------------------------------------------------------------
            | SSH Credentials
            |--------------------------------------------------------------------------
            |
            | Provide the SSH connection details for the server. You can use either
            | password authentication or a private key. Values are read from your
            | environment file for security.
            |
            */
            'ssh' => [
                'host'     => env('SYNCOPS_PRODUCTION_HOST', ''),     // Remote server host (IP or domain)
                'port'     => env('SYNCOPS_PRODUCTION_PORT', 22),     // SSH port
                'username' => env('SYNCOPS_PRODUCTION_USERNAME', ''), // SSH username
                'password' => env('SYNCOPS_PRODUCTION_PASSWORD', ''), // Optional, not needed with private key
                'key_path' => env('SYNCOPS_PRODUCTION_KEY', ''),      // Optional, path to private key file
            ],

            /*
            |--------------------------------------------------------------------------
            | Project Settings
            |--------------------------------------------------------------------------
            |
            | Define the project root path on the remote server and the branch names
            | used for deployment (production branch) and main development tracking.
            |
            */
            'project' => [
                'path'        => rtrim(env('SYNCOPS_PRODUCTION_PATH', ''), '/'), // Project root path
                'branch_prod' => env('SYNCOPS_PRODUCTION_BRANCH_PROD', 'prod'),  // Production branch name
                'branch_main' => env('SYNCOPS_PRODUCTION_BRANCH_MAIN', 'main'),  // Development branch name
            ],

            /*
            |--------------------------------------------------------------------------
            | Permissions (Optional)
            |--------------------------------------------------------------------------
            |
            | These are only required if multiple users have different access rights
            | on the server. SyncOps will adjust ownership and permissions where
            | needed (e.g. for web server writable folders).
            |
            */
            'permissions' => [
                'root_user'   => env('SYNCOPS_PRODUCTION_ROOT_USER'), // Root user and group, e.g. `root:root`
                'web_user'    => env('SYNCOPS_PRODUCTION_WEB_USER'),  // Web user and group, e.g. `www-data:www-data`
                'web_folders' => ['storage', 'themes'],               // Folders owned by web user
            ],

           /*
           |--------------------------------------------------------------------------
           | Remote Database (Optional)
           |--------------------------------------------------------------------------
           |
           | Database credentials are only required when using commands like
           | `syncops:db-pull`. This allows SyncOps to connect to the remote
           | database, create a dump, and import it into your local database.
           |
           | By default, the dump will include all tables from the remote
           | database. If you only want to pull specific tables (for example,
           | excluding large log tables or sensitive user data), list them
           | in the "tables" array below. Only those tables will be included
           | in the dump and imported locally.
           |
           */
            'database'    => [
                'database' => env('SYNCOPS_PRODUCTION_DB_DATABASE'), // Database name
                'username' => env('SYNCOPS_PRODUCTION_DB_USERNAME'), // Database username
                'password' => env('SYNCOPS_PRODUCTION_DB_PASSWORD'), // Database password
                'tables'   => [
                    // Example: only sync custom plugin tables
                    // 'custom_plugin_orders',
                    // 'custom_plugin_customers',
                ],
            ],
        ],
    ],
];
