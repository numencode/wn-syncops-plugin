<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SyncOps Remote Server Connections
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
            'host'     => env('SYNCOPS_PRODUCTION_HOST', ''),     // Remote server host (IP or domain)
            'port'     => env('SYNCOPS_PRODUCTION_PORT', 22),     // SSH port
            'username' => env('SYNCOPS_PRODUCTION_USERNAME', ''), // SSH username
            'password' => env('SYNCOPS_PRODUCTION_PASSWORD', ''), // Optional, not needed with private key
            'key_path' => env('SYNCOPS_PRODUCTION_KEY', ''),      // Optional, path to private key file

            /*
            |--------------------------------------------------------------------------
            | Project Settings
            |--------------------------------------------------------------------------
            |
            | Define the project root path on the remote server and the branch names
            | used for deployment (production branch) and main development tracking.
            |
            */
            'path'        => rtrim(env('SYNCOPS_PRODUCTION_PATH'), '/'),
            'branch_prod' => env('SYNCOPS_PRODUCTION_BRANCH_PROD', 'prod'),
            'branch_main' => env('SYNCOPS_PRODUCTION_BRANCH_MAIN', 'main'),

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
                'root_user'   => env('REMOTE_PRODUCTION_ROOT_USER'),
                'web_user'    => env('REMOTE_PRODUCTION_WEB_USER'),
                'web_folders' => ['storage', 'themes'],
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
                'database' => env('SYNCOPS_PRODUCTION_DB_DATABASE'),
                'username' => env('SYNCOPS_PRODUCTION_DB_USERNAME'),
                'password' => env('SYNCOPS_PRODUCTION_DB_PASSWORD'),
                'tables'   => [
                    // Example: only sync custom plugin tables
                    // 'custom_plugin_orders',
                    // 'custom_plugin_customers',
                ],
            ],
        ],
    ],
];
