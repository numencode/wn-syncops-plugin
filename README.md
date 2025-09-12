---

# ⚠️🚧 **WORK IN PROGRESS** 🚧⚠️
# DO NOT USE IN PRODUCTION

This plugin is currently under active development and is **not ready for production use**.

---

# SyncOps Plugin

The **NumenCode SyncOps** plugin for Winter CMS offers a powerful and streamlined solution for managing backups,
deployments, and environment synchronization. Designed for developers, it simplifies syncing databases, media
files, and code between environments, enabling safer and more efficient DevOps workflows within Winter CMS.

[![Version](https://img.shields.io/github/v/release/numencode/wn-syncops-plugin?style=flat-square&color=0099FF)](https://github.com/numencode/wn-syncops-plugin/releases)
[![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/numencode/wn-syncops-plugin?style=flat-square&color=0099FF)](https://packagist.org/packages/numencode/wn-syncops-plugin)
[![Checks](https://img.shields.io/github/check-runs/numencode/wn-syncops-plugin/main?style=flat-square)](https://github.com/numencode/wn-syncops-plugin/actions)
[![Tests](https://img.shields.io/github/actions/workflow/status/numencode/wn-syncops-plugin/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/numencode/wn-syncops-plugin/actions)
[![License](https://img.shields.io/github/license/numencode/wn-syncops-plugin?label=open%20source&style=flat-square&color=0099FF)](https://github.com/numencode/wn-syncops-plugin/blob/main/LICENSE.md)

---

## Target Audience

The target audience for this plugin includes Winter CMS developers, DevOps engineers, and technical teams who manage
multiple environments (local, staging, production) and require reliable tools for synchronization and deployment.
SyncOps is ideal for teams working on complex, multi-instance projects where keeping databases, media assets,
and codebases aligned is critical. It streamlines routine operations and reduces the risk of manual errors,
supporting a more automated and professional development workflow.

## Installation and setup

This package requires [Winter CMS](https://wintercms.com/) application.

Install the package with Composer:

```bash
composer require numencode/wn-syncops-plugin
```

Run the command:

```bash
php artisan vendor:publish --tag=syncops-config
```

The above command will create a new configuration file, located at `/config/syncops.php`, that contains all the options
you need in order to configure your remote connections. The connections array contains a list of your servers keyed
by name. Simply populate the credentials in the connections array via your environment variables in the `.env` file.

---
---
---
# WIP ON THIS ***********************************************************************************************

#### Configuration

Before using this command, ensure your remote servers are properly configured in your Laravel application's
`config/syncops.php` file. The command will use the SSH connection details from this configuration to connect
to the remote host.

For example, a typical remote configuration for a production server might look like this:

```php
// config/remote.php

return [
    'connections' => [
        'production' => [
            // Set SSH credentials
            'host'      => env('SYNCOPS_PRODUCTION_HOST', ''),  // host IP address
            'port'      => env('SYNCOPS_PRODUCTION_PORT', 22),
            'user'      => env('SYNCOPS_PRODUCTION_USERNAME', ''), // ssh user
            'key_path'  => env('SYNCOPS_PRODUCTION_KEY', ''), // private key file path

            // Set project path and working branch names
            'path'        => rtrim(env('SYNCOPS_PRODUCTION_PATH'), '/'),
            'branch_prod' => env('SYNCOPS_PRODUCTION_BRANCH_PROD', 'prod'),
            'branch_main' => env('SYNCOPS_PRODUCTION_BRANCH_MAIN', 'main'),
        ],
    ],
];
```

> Note that the SSH can authenticate using either a password or an SSH key.

Beside the SSH credentials in the configuration file, you will notice some additional information under the
`backup` array of data. Here is an explanation for every single attribute from it:

| Data                    | Description                                                                    |
|:------------------------|:-------------------------------------------------------------------------------|
| path                    | Path to the project on the remote server, e.g. `/var/www/yourdomain.com`       |
| branch                  | Name of the branch checked-out on the remote server, `prod` by default         |
| branch_main             | Name of the branch checked-out on the local/dev environment, `main` by default |
| permissions.root_user   | Superuser and group with full access, e.g. `root:root` or `pi:pi`              |
| permissions.web_user    | Web server user and group with limited access, e.g. `www-data:www-data`        |
| permissions.web_folders | Folders owned by web user, `storage,themes` by default                         |
| database.name           | Database name on the remote server                                             |
| database.username       | Database username on the remote server                                         |
| database.password       | Database password on the remote server                                         |
| database.tables         | Tables viable for the `db:pull` command                                        |


### Environment variables example

This is an example for the environment variables in the local/dev `.env` file:

    SYNCOPS_PRODUCTION_HOST=123.456.789.10
    SYNCOPS_PRODUCTION_USERNAME=numencode
    SYNCOPS_PRODUCTION_KEY=C:\Users\numencode\.ssh\id_rsa
    SYNCOPS_PRODUCTION_PATH=/home/numencode.com/public_html
    SYNCOPS_PRODUCTION_BRANCH_PROD=prod
    SYNCOPS_PRODUCTION_BRANCH_MAIN=main
    SYNCOPS_PRODUCTION_ROOT_USER=root:root
    SYNCOPS_PRODUCTION_WEB_USER=www-data:www-data
    SYNCOPS_PRODUCTION_DB_DATABASE=numencode_winter
    SYNCOPS_PRODUCTION_DB_USERNAME=numencode_winter
    SYNCOPS_PRODUCTION_DB_PASSWORD="num4nc0d3p455"

Normally, the production `.env` file does not even need these entries.

## Details

This plugin provides various console commands that offer a better experience with backups, remote deployment,
cloud storage for media files, database synchronization and more.

### Definitions

- Local/dev environment is located on the `main` branch by default.
- Production environment is located on the `prod` branch by default.
- Git repository is usually located on GitHub, Gitlab, Bitbucket, etc.
- Cloud storage is defined in `/config/filesystems.php` and can be anything from ftp, sftp, s3, rackspace, dropbox, etc.

## Commands

| Command                                   | Description                                                                                                                  |
|:------------------------------------------|:-----------------------------------------------------------------------------------------------------------------------------|
| [syncops:db-push](#db-push)               | Create a database dump (compressed by default) and optionally upload it to cloud storage.                                    |
| [syncops:db-pull](#db-pull)               | Creates a database dump on a remote server, downloads it, and imports it locally.                                            |
| [syncops:media-push](#media-push)         | Backs up all media files to the specified cloud storage.                                                                     |
| [syncops:media-pull](#media-pull)         | TBD Transfer all the media files from production to the local/dev environment via the cloud storage                          |
| [project:backup](#project-backup)         | TBD Create a compressed archive of all the project files and upload it to the cloud storage                                  |
| [syncops:project-deploy](#project-deploy) | TBD                                                                                                                          |
| [syncops:project-pull](#project-pull)     | Commits untracked changes on the remote server, pushes them to the origin, and optionally merges them into the local branch. |
| [syncops:project-push](#project-push)     | Adds and commits project changes locally and pushes them to the remote repository.                                           |

---














# DONE *****************************************************************************************

<a name="db-pull"></a>
### Command: `syncops:db-pull`

The `syncops:db-pull` command automates the process of creating a database dump on a **remote server**,
downloading it to your local project, and (by default) importing it into your local database.

This command is especially useful for **synchronizing development environments** with production or staging data.

This command performs the following actions:

1. Connects to the specified remote server.
2. Creates a database dump on the remote server.
    - By default, the dump is compressed as a `.sql.gz` file.
    - With the `--no-gzip` option, the dump is saved as a plain `.sql` file.
    - Optionally, you can define specific tables to pull by configuring them in `config/syncops.php`
      under `database.tables`. Only the tables listed there will be included in the dump.
3. Downloads the dump via SFTP to your local project folder.
4. **Optionally** unzips the dump locally (if compression was used).
5. **Optionally** imports the dump into your configured local database.
6. Cleans up temporary dump files both remotely and locally.

#### Configuration

Before using this command, ensure your remote servers are properly configured in your
Laravel application's `config/syncops.php` file. The command will use the SSH connection
details from this configuration to connect to the remote host.

Example `config/syncops.php` snippet for database tables:

```php
'database' => [
    'username' => 'dbuser',
    'password' => 'dbpass',
    'database' => 'my_database',
    'tables'   => [
        'users',
        'posts',
        'comments',
    ],
],
```

If the `tables` array is provided, only these tables will be included in the dump; otherwise, the entire
database will be dumped.

#### Usage in CLI

```bash
php artisan syncops:db-pull my-server
````

#### Options & arguments

* **`server`**: The name of the remote server (as configured in your `syncops.php` config file).

  ```bash
  php artisan syncops:db-pull production
  ```

* **`--timestamp`**: Date format used for naming the dump file (default: `Y-m-d_H-i-s`).

  ```bash
  php artisan syncops:db-pull production --timestamp=d-m-Y
  ```

* **`--no-gzip` (`-g`)**: Skips gzip compression and transfers the dump as a plain `.sql`.

  ```bash
  php artisan syncops:db-pull production --no-gzip
  ```

* **`--no-import` (`-i`)**: Prevents automatic import into the local database after download.
  Useful if you only need the dump file.

  ```bash
  php artisan syncops:db-pull production --no-import
  ```

#### Note

This command currently **only supports MySQL and MariaDB** databases.
Other database types supported by Laravel (PostgreSQL, SQLite, SQL Server, Redis, etc.) are not compatible.

---

<a name="db-push"></a>
### Command: `syncops:db-push`

The `syncops:db-push` command automates the process of creating an optionally compressed SQL dump of your
project’s default MySQL/MariaDB database and, if specified, uploading it to a cloud storage provider.

This command is especially useful for **scheduled backups**, ensuring your production database
is safely stored locally and in the cloud.

This command performs the following actions:

1. Creates a database dump of the configured default database.
   - By default, the dump is compressed as a `.sql.gz` file.
   - With the `--no-gzip` option, the dump is saved as a plain `.sql` file.
2. Names the file using a timestamp (format configurable via option).
3. **Optionally** uploads the file to a cloud storage provider.
4. **Optionally** deletes the local file after upload, unless instructed to keep it.
5. **Optionally** moves the file to a local folder if `--folder` is specified.

#### Configuration

Before using this command, ensure your Laravel application is properly configured:

* **Database configuration** (`config/database.php`) – the command uses the `default`
  connection to determine the database name, username, and password.
* **Filesystem configuration** (`config/filesystems.php`) – if uploading to cloud storage,
  the disk name specified as the `cloud` argument must exist.

#### Usage in CLI

```bash
php artisan syncops:db-push
````

#### Options & arguments

* **`cloud`**: The cloud storage disk (as defined in `/config/filesystems.php`) where the dump file will be uploaded.

  ```bash
  php artisan syncops:db-push dropbox
  ```

* **`--folder`**: The folder name where the dump file will be stored locally and/or on the cloud.

  ```bash
  php artisan syncops:db-push dropbox --folder=database
  ```

* **`--timestamp`**: Date format used for naming the dump file (default: `Y-m-d_H-i-s`).

  ```bash
  php artisan syncops:db-push dropbox --timestamp=d-m-Y
  ```

* **`--no-gzip` (`-g`)**: Skips gzip compression and saves the dump file as a plain `.sql`.

  ```bash
  php artisan syncops:db-push dropbox --no-gzip
  ```

* **`--no-delete` (`-d`)**: Prevents deletion of the local dump file after uploading.

  ```bash
  php artisan syncops:db-push dropbox --no-delete
  ```

#### Usage in Scheduler

To automate your database backups, add the command to your Winter CMS Scheduler
(typically in `app/Plugin.php` or a dedicated service provider's `boot()` method).

```php
$schedule->command('syncops:db-push dropbox --folder=database')->dailyAt('02:00');
```

This example schedules a daily backup of your database to the `dropbox` disk every day at 2:00 AM.

#### Note

This command currently **only supports MySQL and MariaDB** databases.
Other database types supported by Laravel (PostgreSQL, SQLite, SQL Server, Redis, etc.) are not compatible.

---

<a name="media-push"></a>
### Command: `syncops:media-push`

The `syncops:media-push` command efficiently uploads all media files from your Winter CMS installation
(specifically from the `storage/app` directory) to a specified cloud storage disk.

This command is ideal for creating routine media backups. When integrated with the Winter CMS Scheduler, it enables
automated daily backups to your chosen cloud storage, ensuring your media assets are always safe and accessible.

By default, the command excludes `.gitignore` files and any files located within `/thumb/` directories
(which typically contain generated image thumbnails) to optimize upload times and storage space.

#### Configuration

Before using this command, ensure your cloud storage disks are properly configured in your Laravel application's
`config/filesystems.php` file. For example, a basic Dropbox configuration might look like this:

```php
// config/filesystems.php

'disks' => [
    // ... other disks

    'dropbox' => [
        'driver' => 'dropbox',
    ],
],
```

#### Usage in CLI

To run the command, you need to specify the cloud storage disk name (as defined in your `config/filesystems.php`).

```bash
php artisan syncops:media-push dropbox
```

In this example, `dropbox` is the name of your configured cloud storage disk.

You can also specify an optional target folder within your cloud storage.
This folder will serve as the base directory for your uploaded media files.

```bash
php artisan syncops:media-push dropbox my-project-media
```

If `my-project-media` is provided, files will be uploaded to a path like `dropbox://my-project-media/storage/...`.
If no folder is specified, the default target folder on the cloud storage will be `storage/`.

#### Options & arguments

The `syncops:media-push` command supports the following options for more control over the backup process:

* **`--log` (`-L`)**: Display details for each file as it's processed. This includes messages for files being
  uploaded and files that are skipped because they already exist and have the same size in the cloud storage.
  When this option is used, the progress bar will be hidden.

  ```bash
  php artisan syncops:media-push dropbox --log
  ```

* **`--dry-run` (`-D`)**: Simulate the backup process without actually uploading any files.
  This allows you to see which files would be processed, making it useful for testing and verifying your setup.

  ```bash
  php artisan syncops:media-push dropbox --dry-run
  ```

#### Usage in Scheduler

To automate your media backups, add the command to your Winter CMS Scheduler
(typically in `app/Plugin.php` or a dedicated service provider's `boot()` method).

```php
$schedule->command('syncops:media-push dropbox')->dailyAt('03:00');
```

This example schedules a daily backup of your media files to the `dropbox` disk every day at 3:00 AM.

---

<a name="project-pull"></a>
### Command: `syncops:project-pull`

The `syncops:project-pull` command automates the process of synchronizing a remote production environment's code
changes with your local development project. It's particularly useful for Winter CMS projects where content changes
made in the backend (pages, layouts, etc.) are saved as static `.htm` files.

This command performs the following actions:

1.  Checks for and commits any untracked changes on the remote server.
2.  **Optionally** executes a `git pull` on the remote to ensure it's up-to-date before pushing.
3.  Pushes the committed changes from the remote server to your Git repository.
4.  **Optionally** fetches the changes and merges them into your current local branch.

This streamlined workflow ensures you can quickly and safely retrieve and integrate content updates made directly
on your production site, without manual intervention.

#### Usage in CLI

To run the command, you need to specify the name of the remote server, as configured in your `config/remote.php` file.

```bash
php artisan syncops:project-pull production
```

In this example, `production` is the name of your configured remote server.

#### Configuration

Before using this command, ensure your remote servers are properly configured in your
Laravel application's `config/syncops.php` file. The command will use the SSH connection
details from this configuration to connect to the remote host.

#### Options & arguments

The `syncops:project-pull` command supports the following options for more control over the synchronization process:

* **`--pull` (`-p`)**: Executes a `git pull` on the remote server before pushing changes. Use this to ensure the
  remote server's branch is fully up-to-date with the repository's origin before pushing its new changes.

  ```bash
  php artisan syncops:project-pull production --pull
  ```

* **`--no-merge` (`-m`)**: Prevents the command from automatically merging changes into your local branch
  after pushing them to the repository. This is useful if you want to inspect the changes on your
  local machine before merging them manually.

  ```bash
  php artisan syncops:project-pull production --no-merge
  ```

* **`--message`**: Specify a custom commit message for the changes on the remote server.
  If this option is not used, the default commit message will be **"Server changes"**.

  ```bash
  php artisan syncops:project-pull production --message="Updated content and layout"
  ```

---

<a name="project-push"></a>
### Command: `syncops:project-push`

The `syncops:project-push` command automates the process of committing and pushing local project changes to your
Git repository. It’s especially useful when working on Winter CMS projects where content or code adjustments
have been made directly on a development or staging server, and you want to persist those changes in Git.

This command performs the following actions:

1. Checks for uncommitted changes in your local working directory.
2. If changes are detected, stages all modified and new files (`git add --all`).
3. Commits the changes with either a default or custom commit message.
4. Pushes the commit to your remote Git repository.

This ensures that local adjustments are always tracked and versioned,
keeping your project repository in sync with your environment.

#### Configuration

This command runs entirely **locally** and does not require a remote server configuration.
It uses your current Git settings (branch, remote, and authentication) to push changes.

#### Usage in CLI

To run the command:

```bash
php artisan syncops:project-push
```

By default, this will commit with the message **"Server changes"** and push them to your configured remote.

#### Options & arguments

The `syncops:project-push` command supports the following option:

* **`--message` (`-m`)**: Specify a custom commit message for the changes.
  If not provided, the default message is **"Server changes"**.

  ```bash
  php artisan syncops:project-commit --message="Custom commit message"
  ```


---













---
---
---

<a name="media-pull"></a>
### Media:pull

The command connects to a remote server with SSH and executes `php artisan media:backup cloudName` in order to upload
all the media files to the cloud storage first. After that it downloads all the media files from the cloud storage
to the local file storage. The media files are synchronized between both environments and also the cloud storage.

The command is intended to be executed on a local/dev environment in order to update the local media storage with the
media files from the production/staging environment.

#### Usage in CLI

```bash
php artisan media:pull production cloudName
```
- where `production` is the remote server name (defined in `/config/syncops.php`)
- where `cloudName` is a cloud storage where the files are uploaded (defined in `/config/filesystems.php`)

The command supports some optional arguments:
```bash
php artisan media:pull production cloudName folder --sudo
```
- `folder` is the name of the folder on the cloud storage where the media files are uploaded, the default is `storage/`
- `--sudo` or `-x` forces superuser (sudo) on the remote server

---

<a name="project-backup"></a>
### Project:backup

The command creates a compressed tarball file, which is an archive of all the project files in the current directory.
The name of the archive is the current timestamp with the extension of `.tar.gz`. The timestamp format can be
explicitly specified - the default format is `Y-m-d_H-i-s`. The command can also upload the file to the cloud
storage, if an argument is provided. You can exclude explicitly selected folders from the archive.

This command is useful if it's set in the Scheduler to create a complete backup once a week and upload it to the
cloud storage.

#### Usage in CLI

```bash
php artisan project:backup
```

The command supports some optional arguments:
```bash
php artisan project:backup cloudName --folder=files --timestamp=d-m-Y --exclude=files --no-delete
```
- `cloudName` is the cloud storage where the archive is uploaded (defined in `/config/filesystems.php`)
- `--folder` is the name of the folder where the archive is stored (local and/or on the cloud storage)
- `--timestamp` is a date format used for naming the archive file (default: `Y-m-d_H-i-s`)
- `--exclude` is a comma-separated list of the folders, to be excluded from the archive (`/vendor` is excluded by default)
- `--no-delete` or `-d` prevents deleting the local archive file after it's uploaded to the cloud storage

#### Usage in Scheduler

```
$schedule->command('project:backup dropbox --folder=files')->weeklyOn(1, '01:00');
```

---

### Project:deploy

The command puts the app into maintenance mode, clears all the cache, pulls the changes from the git repository -
from the branch `main` (by default), merges them into branch `prod` (by default), rebuilds the cache and turns off
the maintenance mode. If there are changes present in `composer.lock` file, the packages are updated automatically
and the migrations can be run with the parameter `-m`.

#### Usage in CLI

```bash
php artisan project:deploy production
```
- where `production` is the remote server name (defined in `/config/syncops.php`)

The command supports some optional arguments:
```bash
php artisan project:deploy production --fast --composer --migrate --sudo
```
- where `--fast` or `-f` is an optional argument which deploys without clearing the cache
- where `--composer` or `-c` is an optional argument which forces Composer install
- where `--migrate` or `-m` is an optional argument which runs migrations (`php artisan winter:up`)
- where `--sudo` or `-x` is an optional argument which forces the superuser (`sudo`) usage

---


---

## TBD -------------- Recommended Scheduler settings

Here are the recommended entries for the Scheduler:
- create a complete backup every monday at 1 am
- create a backup of all media files every day at 2 am
- create a backup of the database every day at 3 am
- commit changes from the production environment every day at 4 am

```
$schedule->command('project:backup dropbox --folder=files')->weeklyOn(1, '01:00');
$schedule->command('db:backup dropbox --folder=database')->daily()->at('02:00');
$schedule->command('media:backup dropbox')->daily()->at('03:00');
$schedule->command('project:commit')->daily()->at('04:00');
```

---

## Dropbox setup

Dropbox is very easy to configure and upon the registration on https://www.dropbox.com/register
it offers free 2GB of cloud storage space.
In order to setup the Dropbox, complete the registration, add the
[NumenCode Dropbox Adapter Plugin](https://packagist.org/packages/numencode/wn-dropboxadapter-plugin)
and follow the [Installation steps here](https://github.com/numencode/wn-dropboxadapter-plugin/blob/main/README.md).

---

# Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

# Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

# Security

If you discover any security-related issues, please email info@numencode.com instead of using the issue tracker.

# Author

**NumenCode.SyncOps** plugin was created by and is maintained by [Blaz Orazem](https://www.orazem.si/).

Please write an email to info@numencode.com about all the things concerning this project.

Follow [@blazorazem](https://twitter.com/blazorazem) on Twitter.

# License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

[![MIT License](https://img.shields.io/github/license/numencode/wn-syncops-plugin?label=License&color=blue&style=flat-square&cacheSeconds=600)](https://github.com/numencode/wn-syncops-plugin/blob/main/LICENSE.md)
