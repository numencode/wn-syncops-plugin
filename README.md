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

## Configuration

Before using SyncOps, you must define your **remote servers and project settings** in `config/syncops.php`.
Each connection entry (e.g. `production`, `staging`) describes how SyncOps should connect and operate on that server.

### Timestamp

```php
'timestamp' => 'Y-m-d_H_i_s',
```

Defines the default timestamp format used for naming files (backups, database dumps, archives).
Defaults to `Y-m-d_H_i_s`.

---

### Connections

All remote servers are defined under the `connections` array.
Each server is keyed by a name (e.g. `production`, `staging`) and contains:

#### SSH Credentials

* `host` → Remote server host (IP or domain)
* `port` → SSH port (default: `22`)
* `username` → SSH username
* `password` → (Optional) SSH password (not needed with key auth)
* `key_path` → (Optional) Path to private key file

> 🔒 For security, these values are typically provided via environment variables rather than hardcoded.

#### Project Settings

* `path` → Absolute path to the project root on the remote server
* `branch_prod` → Name of the production branch (default: `prod`)
* `branch_main` → Name of the main development branch (default: `main`)

#### Permissions (Optional)

If your server uses different users for deployment vs. web server runtime, you can define ownership rules:

* `root_user` → Superuser and group with full access, e.g. `root:root`
* `web_user` → Web server user/group, e.g. `www-data:www-data`
* `web_folders` → Array of folders owned by the web user (defaults: `storage`, `themes`)

#### Remote Database (Optional)

Required only when using `syncops:db-pull` or related database commands.

* `database` → Database name on the remote server
* `username` → Database username
* `password` → Database password
* `tables` → (Optional) Restrict synchronization to specific tables

---

### Environment Variables

Typical `.env` configuration for a **production server** might look like this:

```dotenv
SYNCOPS_PRODUCTION_HOST=123.456.789.10
SYNCOPS_PRODUCTION_PORT=22
SYNCOPS_PRODUCTION_USERNAME=deploy
SYNCOPS_PRODUCTION_KEY=C:\Users\me\.ssh\id_rsa
SYNCOPS_PRODUCTION_PATH=/var/www/example.com
SYNCOPS_PRODUCTION_BRANCH_PROD=prod
SYNCOPS_PRODUCTION_BRANCH_MAIN=main

# Optional permission settings
# REMOTE_PRODUCTION_ROOT_USER=root:root
# REMOTE_PRODUCTION_WEB_USER=www-data:www-data

# Optional database settings
SYNCOPS_PRODUCTION_DB_DATABASE=example_db
SYNCOPS_PRODUCTION_DB_USERNAME=example_user
SYNCOPS_PRODUCTION_DB_PASSWORD="secret"
```

⚠️ On the **remote server**, you generally don’t need to replicate these environment variables —
they are only required in your **local/dev environment** to allow SyncOps to connect.

---

## Commands overview

| Command                                   | Description                                                                                                              |
|:------------------------------------------|:-------------------------------------------------------------------------------------------------------------------------|
| [syncops:db-pull](#db-pull)               | Create a database dump on a remote server, download it, and import it locally.                                           |
| [syncops:db-push](#db-push)               | Create a database dump (compressed by default) and optionally upload it to cloud storage.                                |
| [syncops:media-pull](#media-pull)         | Download media files from the remote server via SFTP into local storage.                                                 |
| [syncops:media-push](#media-push)         | Back up all media files to the specified cloud storage.                                                                  |
| [syncops:project-backup](#project-backup) | Create a compressed archive of project files and optionally upload it to cloud storage.                                  |
| [syncops:project-deploy](#project-deploy) | Deploy the project to a remote server via Git, with optional cache clearing, Composer install, and migrations.           |
| [syncops:project-pull](#project-pull)     | Commit untracked changes on the remote server, push them to the origin, and optionally merge them into the local branch. |
| [syncops:project-push](#project-push)     | Add and commit project changes locally and push them to the remote repository.                                           |

---

<a name="db-pull"></a>
### Command: `syncops:db-pull`

Create a database dump on a **remote server**, download it to your local project,
and (by default) import it into your local database.

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

#### Usage

```bash
php artisan syncops:db-pull {server} [options]
````

#### Arguments

| Argument | Description                                                                                                                              |
|----------|------------------------------------------------------------------------------------------------------------------------------------------|
| `server` | The name of the remote server, as defined in your `syncops.php` configuration file.<br>Example: `php artisan syncops:db-pull production` |

#### Options

| Option              | Description                                                                                                                                                                         |
|---------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--timestamp=`      | Date format used for naming the dump file. The default is defined in `config/syncops.php`.<br>Example: `php artisan syncops:db-pull production --timestamp=d-m-Y`                   |
| `-g`, `--no-gzip`   | Skips the gzip compression process and transfers the database dump as a plain `.sql` file.<br>Example: `php artisan syncops:db-pull production --no-gzip`                           |
| `-i`, `--no-import` | Prevents the database dump from being automatically imported into the local database after it has been downloaded.<br>Example: `php artisan syncops:db-pull production --no-import` |

#### Note

> This command currently **only supports MySQL and MariaDB** databases.
> Other database types supported by Laravel (PostgreSQL, SQLite, SQL Server, Redis, etc.) are not compatible.

---

<a name="db-push"></a>
### Command: `syncops:db-push`

Create a database dump (compressed by default) of your project’s default MySQL/MariaDB database,
and, if specified, upload it to a cloud storage provider.

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

This command relies on Laravel’s **Filesystem configuration** if uploading to cloud storage.
Make sure the chosen cloud storage disk is defined in `config/filesystems.php`.

#### Usage

```bash
php artisan syncops:db-push {cloud?} [options]
````

#### Arguments

| Argument | Description                                                                                                                                                                                  |
|----------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `cloud`  | The optional name of the cloud storage disk where the database dump file will be uploaded. Must be configured in `config/filesystems.php`.<br>Example: `php artisan syncops:db-push dropbox` |

#### Options

| Option              | Description                                                                                                                                                                  |
|---------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--folder=`         | The name of the folder where the dump file will be stored both locally and on the cloud.<br>Example: `php artisan syncops:db-push dropbox --folder=database`                 |
| `--timestamp=`      | Date format used for naming the dump file. The default is defined in `config/syncops.php`.<br>Example: `php artisan syncops:db-push dropbox --timestamp=d-m-Y`               |
| `-g`, `--no-gzip`   | Skips the gzip compression process, saving the dump file as a plain `.sql` file instead of a compressed archive.<br>Example: `php artisan syncops:db-push dropbox --no-gzip` |
| `-d`, `--no-delete` | Prevents the local dump file from being deleted after it has been successfully uploaded to the cloud storage.<br>Example: `php artisan syncops:db-push dropbox --no-delete`  |

#### Usage in Scheduler

To automate your database backups, add the command to your Winter CMS Scheduler
(typically in `app/Plugin.php` or a dedicated service provider's `boot()` method).

```php
$schedule->command('syncops:db-push dropbox --folder=database')->dailyAt('02:00');
```

This example schedules a daily backup of your database to the `dropbox` disk every day at 2:00 AM.

#### Note

> This command currently **only supports MySQL and MariaDB** databases.
> Other database types supported by Laravel (PostgreSQL, SQLite, SQL Server, Redis, etc.) are not compatible.

---

<a name="media-pull"></a>
### Command: `syncops:media-pull`

Download media files from a **remote server** via SFTP directly into your local `storage/app` directory.

This command is especially useful for **synchronizing media files** in development
environments with production data without including them in your Git repository.

This command performs the following actions:

1. Connects to the specified remote server via SFTP.
2. Recursively fetches all files under the remote `storage/app` directory.
3. **Skips certain directories and files** according to the rules below.
4. Downloads each file into your local `storage/app` folder, creating directories as needed.
5. Optionally skips overwriting local files if `--no-overwrite` is provided.
6. Ensures file sizes match to avoid unnecessary downloads when the local file is identical.

#### Configuration

Before using this command, ensure your remote servers are properly configured in your
Laravel application's `config/syncops.php` file. The command will use the SSH connection
details from this configuration to connect to the remote host.

#### Usage

```bash
php artisan syncops:media-pull {server} [options]
````

#### Arguments

| Argument | Description                                                                                                                                 |
|----------|---------------------------------------------------------------------------------------------------------------------------------------------|
| `server` | The name of the remote server, as defined in your `syncops.php` configuration file.<br>Example: `php artisan syncops:media-pull production` |

#### Options

| Option           | Description                                                                                                                                                                                             |
|------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--no-overwrite` | Prevents the command from overwriting local files that already exist, which is useful for only downloading new or updated files.<br>Example: `php artisan syncops:media-pull production --no-overwrite` |

#### Behavior

* The remote path scanned is always `storage/app`.
* Files inside any `/thumb/` or `/resized/` directories are **skipped**.
* Files starting with a dot (hidden files) are **skipped**, **except** `.gitignore` files which are always downloaded.
* Only files that either do not exist locally or have a different file size are downloaded (unless `--no-overwrite` is used).
* All directory structures from the remote server are preserved locally.
* Files are downloaded **directly via SFTP**, no intermediate cloud storage is used.

---

<a name="media-push"></a>
### Command: `syncops:media-push`

Efficiently upload all media files from your Winter CMS installation (specifically from the `storage/app` directory)
to a specified cloud storage disk.

This command is ideal for creating routine media backups. When integrated with the Winter CMS Scheduler, it enables
automated daily backups to your chosen cloud storage, ensuring your media assets are always safe and accessible.

By default, the command excludes `.gitignore` files and any files located within `/thumb/` directories
(which typically contain generated image thumbnails) to optimize upload times and storage space.

#### Configuration

This command relies on Laravel’s **Filesystem configuration**.
Make sure the chosen cloud storage disk is defined in `config/filesystems.php`.

#### Usage

To run the command, you need to specify the cloud storage disk name (as defined in your `config/filesystems.php`).

```bash
php artisan syncops:media-push {cloud} [options]
```

You can also specify an optional target folder within your cloud storage which will serve as the base directory for
your uploaded media files. If no folder is specified, the default target folder on the cloud storage will be `storage/`.

#### Arguments

| Argument | Description                                                                                                                                                                                   |
|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `cloud`  | The required name of the cloud storage disk. Must be configured in `config/filesystems.php`.<br>Example: `php artisan syncops:media-push dropbox`                                             |

#### Options

| Option            | Description                                                                                                                                                                                |
|-------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--folder=`       | The optional target folder where the media files will be stored. Applies both locally and on cloud storage.<br>Example: `php artisan syncops:media-push dropbox --folder=media/my-project` |
| `-l`, `--log`     | Displays details for each file as it is processed, including files that are uploaded and those that are skipped.<br>Example: `php artisan syncops:media-push dropbox --log`                |
| `-d`, `--dry-run` | Simulates the upload process without actually transferring any files, which is useful for testing and verifying your setup.<br>Example: `php artisan syncops:media-push dropbox --dry-run` |

#### Usage in Scheduler

To automate your media backups, add the command to your Winter CMS Scheduler
(typically in `app/Plugin.php` or a dedicated service provider's `boot()` method).

```php
$schedule->command('syncops:media-push dropbox')->dailyAt('03:00');
```

This example schedules a daily backup of your media files to the `dropbox` disk every day at 3:00 AM.

---

<a name="project-backup"></a>
### Command: `syncops:project-backup`

Create a compressed archive of all project files and optionally upload it to the configured cloud storage.

#### Configuration

This command relies on Laravel’s **Filesystem configuration**.
Make sure the chosen cloud storage disk is defined in `config/filesystems.php`.

#### Usage

```bash
php artisan syncops:project-backup {cloud?} [options]
```

#### Arguments

| Argument | Description                                                                                                                                                                              |
|----------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `cloud`  | The optional name of the cloud storage disk where the archive will be uploaded. Must be configured in `config/filesystems.php`.<br>Example: `php artisan syncops:project-backup dropbox` |

#### Options

| Option              | Description                                                                                                                                                                                                                                                                    |
|---------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--folder=`         | The folder where the archive will be stored. Applies both locally and on cloud storage.<br>Example: `php artisan syncops:project-backup --folder=backups/my-project`                                                                                                           |
| `--timestamp=`      | Date format used for naming the archive file. The default is defined in `config/syncops.php`.<br>Example: `php artisan syncops:project-backup --timestamp=Y-m-d`                                                                                                               |
| `--exclude=`        | Comma-separated list of folders to exclude from the archive. Folders `storage/framework/cache`, `/vendor` and backup folder (as defined with `--folder`) are always excluded by default.<br>Example: `php artisan syncops:project-backup --exclude=node_modules,storage,tests` |
| `-d`, `--no-delete` | If set, the local archive file will **not** be deleted after upload. Instead, it will be moved into the `--folder` (if specified).<br>Example: `php artisan syncops:project-backup --no-delete`                                                                                |

#### Usage in Scheduler

To automate your project backups, add the command to your Winter CMS Scheduler
(typically in `app/Plugin.php` or a dedicated service provider's `boot()` method).

```php
$schedule->command('syncops:project-backup dropbox --folder=_backup')->weekly()->mondays()->at('2:00')
```

This example schedules a daily backup of your project to the `dropbox` disk every monday at 2:00 AM.

---

<a name="project-deploy"></a>
### Command: `syncops:project-deploy`

Deploy the project to a remote server via **Git**, with optional cache clearing, Composer install, and migrations.
This command ensures a clean remote working tree before continuing. If uncommitted changes are detected on the server,
the deployment will be aborted with a notice to run [`syncops:project-pull`](#project-pull).

#### Usage

```bash
php artisan syncops:project-deploy {server} [options]
```

#### Configuration

Before using this command, ensure your remote servers are properly configured in your
Laravel application's `config/syncops.php` file. The command will use the SSH connection
details from this configuration to connect to the remote host.

#### Arguments

| Argument | Description                                                                                                                          |
|----------|--------------------------------------------------------------------------------------------------------------------------------------|
| `server` | The name of the remote server (as defined in `config/syncops.php`).<br>Example: `php artisan syncops:project-pull production --pull` |

#### Options

| Option             | Description                                                                                                                             |
|--------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `-f`, `--fast`     | Perform a fast deploy without clearing or rebuilding the cache.<br>Example: `php artisan syncops:project-pull production --fast`        |
| `-c`, `--composer` | Force `composer install --no-dev` on the remote server.<br>Example: `php artisan syncops:project-pull production --composer`            |
| `-m`, `--migrate`  | Run database migrations (`php artisan winter:up`) after deployment.<br>Example: `php artisan syncops:project-pull production --migrate` |
| `-x`, `--sudo`     | Execute remote commands as super user (`sudo`).<br>Example: `php artisan syncops:project-pull production --sudo`                        |

#### Behavior

* Checks if the remote working directory is **clean**:
    - If not, the process is aborted, and you will be prompted to run `syncops:project-pull`.
* Two deployment modes are supported:
    - **Full deploy (default)**: Puts the app in maintenance mode, clears caches,
      updates via Git, rebuilds caches, and brings the app back up.
    - **Fast deploy (`--fast`)**: Skips cache clearing/rebuilding, updates via Git directly.
* **Deployment strategies**:
    - **Pull mode** (`branch_main` set to `false` in config): Runs `git pull`.
    - **Merge mode** (`branch_main` set to a branch name): Runs `git fetch` and `git merge origin/{branch}`.
    - If `branch` or `branch_prod` is configured, the remote branch is pushed back to origin after a successful merge.
* **Post-deploy steps**:
    - Runs Composer install (with `--no-dev` option) if `--composer` is specified or if `composer.lock` changed.
    - Runs migrations if `--migrate` is specified.
    - Adjusts file/folder ownership according to `permissions` config.

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

#### Usage

To run the command, you need to specify the name of the remote server, as configured in your `config/remote.php` file.

```bash
php artisan syncops:project-pull {server} [options]
```

#### Configuration

Before using this command, ensure your remote servers are properly configured in your
Laravel application's `config/syncops.php` file. The command will use the SSH connection
details from this configuration to connect to the remote host.

### Arguments

| Argument | Description                                                                                                                          |
|----------|--------------------------------------------------------------------------------------------------------------------------------------|
| `server` | The name of the remote server (as defined in `config/syncops.php`).<br>Example: `php artisan syncops:project-pull production --pull` |

### Options

| Option              | Description                                                                                                                                                                                                                                                                                     |
|---------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `-p`, `--pull`      | Executes a `git pull` on the remote server before pushing changes. Use this to ensure the remote server's branch is fully up-to-date with the repository's origin before pushing its new changes.<br>Example: `php artisan syncops:project-pull production --pull`                              |
| `-m`, `--no-merge`, | Prevents the command from automatically merging changes into your local branch after pushing them to the repository. This is useful if you want to inspect the changes on your local machine before merging them manually.<br>Example: `php artisan syncops:project-pull production --no-merge` |
| `--message=`        | Specify a custom commit message for the changes on the remote server. If this option is not used, the default commit message is **"Server changes"**.<br>Example: `php artisan syncops:project-pull production --message="Updated content and layout"`                                          |

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

#### Usage

To run the command:

```bash
php artisan syncops:project-push [options]
```

By default, this will commit with the message **"Server changes"** and push them to your configured remote.

### Options

| Option       | Description                                                                                                                                                                                                            |
|--------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--message=` | Specify a custom commit message for the changes. If this option is not used, the default commit message is **"Server changes"**.<br>Example: `php artisan syncops:project-push --message="Updated content and layout"` |

---

## Recommended Scheduler settings

The recommended entries for the Scheduler are as follows:
- create a complete project backup every monday at 1 am and push it to a cloud
- create a backup of all media files every day at 2 am and push it to a cloud
- create a backup of the database every day at 3 am  and push it to a cloud
- commit changes from the production environment every day at 4 am

```
$schedule->command('syncops:project-backup dropbox --folder=files')->weeklyOn(1, '01:00');
$schedule->command('syncops:db-push dropbox --folder=database')->daily()->at('02:00');
$schedule->command('syncops:media-push dropbox')->daily()->at('03:00');
$schedule->command('syncops:project-push')->daily()->at('04:00');
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
