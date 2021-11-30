<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Remote Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default connection that will be used for SSH
    | operations. This name should correspond to a connection name below
    | in the server list. Each connection will be manually accessible.
    |
    */

    'default' => 'production',

    /*
    |--------------------------------------------------------------------------
    | Remote Server Connections
    |--------------------------------------------------------------------------
    |
    | These are the servers that will be accessible via the SSH task runner
    | facilities of Laravel. This feature radically simplifies executing
    | tasks on your servers, such as deploying out these applications.
    |
    */

    'connections' => [
        'production' => [
            'host'      => env('REMOTE_PRODUCTION_HOST', ''),
            'username'  => env('REMOTE_PRODUCTION_USERNAME', ''),
            'password'  => env('REMOTE_PRODUCTION_PASSWORD', ''),
            'key'       => env('REMOTE_PRODUCTION_KEY', ''),
            'keytext'   => env('REMOTE_PRODUCTION_KEYTEXT', ''),
            'keyphrase' => env('REMOTE_PRODUCTION_KEYPHRASE', ''),
            'agent'     => env('REMOTE_PRODUCTION_AGENT', ''),
            'timeout'   => 600,

            // Extra data is required for the NumenCode\Backup plugin
            'backup'    => [
                'path'        => rtrim(env('REMOTE_PRODUCTION_PATH'), '/'),
                'branch'      => env('REMOTE_PRODUCTION_BRANCH', 'prod'),
                'branch_main' => env('REMOTE_PRODUCTION_BRANCH_MAIN', 'main'),

                // Permissions are required if there are multiple users with different access rights on the server
                'permissions' => [
                    'root_user'   => env('REMOTE_PRODUCTION_ROOT_USER'),
                    'web_user'    => env('REMOTE_PRODUCTION_WEB_USER'),
                    'web_folders' => 'storage,themes',
                ],

                // Remote database credentials are only required for the db:pull command
                'database'    => [
                    'name'     => env('REMOTE_DB_DATABASE'),
                    'username' => env('REMOTE_DB_USERNAME'),
                    'password' => env('REMOTE_DB_PASSWORD'),

                    // Only tables specified in this array will be viable for the db:pull command.
                    // If no tables are specified, all tables are taken into account.
                    'tables'   => [
                        // Custom
//                        'custom_plugin_table_example',
                        // NumenCode
//                        'numencode_blogextension_files',
//                        'numencode_blogextension_pictures',
//                        'numencode_widgets_features_groups',
//                        'numencode_widgets_features_items',
//                        'numencode_widgets_highlights_groups',
//                        'numencode_widgets_highlights_items',
//                        'numencode_widgets_promotions_groups',
//                        'numencode_widgets_promotions_items',
                        // Winter
//                        'winter_blog_categories',
//                        'winter_blog_posts',
//                        'winter_blog_posts_categories',
//                        'winter_sitemap_definitions',
//                        'winter_translate_attributes',
//                        'winter_translate_indexes',
//                        'winter_translate_locales',
//                        'winter_translate_messages',
                        // System
                        'system_files',
                        'system_mail_layouts',
                        'system_mail_partials',
                        'system_mail_templates',
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Server Groups
    |--------------------------------------------------------------------------
    |
    | Here you may list connections under a single group name, which allows
    | you to easily access all of the servers at once using a short name
    | that is extremely easy to remember, such as "web" or "database".
    |
    */

    'groups' => [
        'web' => ['production'],
    ],

];
