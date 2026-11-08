# SyncOps Plugin

The **SyncOps Plugin** adds deployment, synchronization and backup commands to Winter CMS: deploy a project to a
server with Git, pull a server's database and media files to your machine, and back up databases, media and project
files to cloud storage such as Dropbox.

[![Version](https://img.shields.io/github/v/release/numencode/wn-syncops-plugin?style=flat-square&color=0099FF)](https://github.com/numencode/wn-syncops-plugin/releases)
[![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/numencode/wn-syncops-plugin?style=flat-square&color=0099FF)](https://packagist.org/packages/numencode/wn-syncops-plugin)
[![Tests](https://img.shields.io/github/actions/workflow/status/numencode/wn-syncops-plugin/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/numencode/wn-syncops-plugin/actions)
[![License](https://img.shields.io/github/license/numencode/wn-syncops-plugin?label=open%20source&style=flat-square&color=0099FF)](https://github.com/numencode/wn-syncops-plugin/blob/main/LICENSE.md)

---

## Features

| Command | What it does | Typically run |
|---|---|---|
| [`syncops:project-deploy`](#deploying) | Deploy with Git: merge, Composer, migrations, cache, maintenance mode | on your machine |
| [`syncops:project-pull`](#bringing-server-changes-into-git) | Commit changes made on the server, push them and merge them locally | on your machine |
| [`syncops:project-commit`](#bringing-server-changes-into-git) | Commit and push all changes of the project it runs in | on the server (scheduler) |
| [`syncops:db-pull`](#pulling-a-database) | Copy a server's database into your local database | on your machine |
| [`syncops:media-pull`](#pulling-media-files) | Download a server's `storage/app` files | on your machine |
| [`syncops:db-backup`](#backups) | Back up the database (and upload it to cloud storage) | on the server (scheduler) |
| [`syncops:media-backup`](#backups) | Back up `storage/app` to cloud storage | on the server (scheduler) |
| [`syncops:project-backup`](#backups) | Back up the project files as a `.tar.gz` archive | on the server (scheduler) |
| [`syncops:remote-artisan`](#running-commands-on-a-server) | Run an artisan command on a server | on your machine |
| [`syncops:remote-migrate`](#running-commands-on-a-server) | Run the Winter migrations on a server | on your machine |
| [`syncops:remote-health`](#checking-a-server) | Show the health of a server | on your machine |
| [`syncops:validate`](#checking-a-server) | Check the configuration and test the connections | on your machine |

Security:

- SSH with a private key (optionally encrypted) or a password, and **host key verification** against a pinned
  fingerprint.
- Database passwords never appear on a command line: they are passed in temporary option files.
- Secrets are hidden (`***`) in every message.
- Temporary files are created in private directories and always removed.

## Requirements

- PHP 8.2 or newer
- [Winter CMS](https://wintercms.com) 1.2 (tested with 1.2.15)
- Git on your machine and on the server; MySQL or MariaDB for the database commands
- SSH access to the server (any user that can run Git, PHP and Composer in the project)
- Optional: a cloud storage disk for backups, e.g. with the
  [NumenCode Dropbox Adapter](https://github.com/numencode/wn-dropboxadapter-plugin)

## Installation

```bash
composer require numencode/wn-syncops-plugin
php artisan vendor:publish --tag=syncops-config
```

The second command creates `config/syncops.php`. Settings that are not in your copy use the plugin's defaults, so
you only need to change what differs.

## Configuration

Each server is a connection in `config/syncops.php`, with the name used as the `{server}` argument of the commands.
The default file has a `production` connection that reads its values from the `.env` file:

```dotenv
# SSH
SYNCOPS_PRODUCTION_HOST=203.0.113.10
SYNCOPS_PRODUCTION_PORT=22
SYNCOPS_PRODUCTION_USERNAME=deploy
SYNCOPS_PRODUCTION_KEY=/home/me/.ssh/id_ed25519
SYNCOPS_PRODUCTION_KEY_PASSPHRASE=
SYNCOPS_PRODUCTION_HOST_FINGERPRINT=SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8

# The project on the server
SYNCOPS_PRODUCTION_PATH=/var/www/example.com
SYNCOPS_PRODUCTION_BRANCH_PROD=prod
SYNCOPS_PRODUCTION_BRANCH_MAIN=main
SYNCOPS_PRODUCTION_PHP=php8.4
SYNCOPS_PRODUCTION_COMPOSER="php8.4 /usr/bin/composer"

# The server's database (db-pull, remote-health)
SYNCOPS_PRODUCTION_DB_DATABASE=example
SYNCOPS_PRODUCTION_DB_USERNAME=example
SYNCOPS_PRODUCTION_DB_PASSWORD="secret"
```

| Setting | Description |
|---|---|
| `ssh.host`, `ssh.port`, `ssh.username` | The SSH login. |
| `ssh.key_path` / `ssh.password` | A private key (recommended) or a password. |
| `ssh.key_passphrase` | The passphrase of an encrypted private key. |
| `ssh.host_fingerprint` | The server's host key fingerprint (`SHA256:…`). When it is set, SyncOps refuses to connect to a server with a different key. Without it, every command shows a warning with the server's fingerprint. |
| `ssh.timeout` | Time limit for each remote command in seconds; `0` (default) means no limit. |
| `project.path` | The project root on the server (`~/…` is allowed). |
| `project.branch_prod` | The branch checked out on the server (`prod`). |
| `project.branch_main` | The branch `project-deploy` merges into it (`main`); `false` deploys with `git pull` instead. |
| `project.php`, `project.composer` | The PHP and Composer commands on the server, e.g. `php8.4` and `php8.4 /usr/bin/composer` when the server's default PHP is another version. Commands over SSH don't load shell aliases. |
| `project.temp_path` | Where temporary files (database dumps) are created on the server; default: the system's temporary directory. |
| `permissions.root_user`, `permissions.web_user`, `permissions.web_folders` | Optional ownership changes after a deployment (see [Deploying](#deploying)). |
| `database.*` | The server's database: `database`, `username`, `password`, optional `host`, `port`, and `tables` to limit `db-pull` to some tables. |

Global settings:

| Setting | Description |
|---|---|
| `timestamp` | Date format for backup file names (`Y-m-d_H_i_s`). |
| `backup_path` | Local folder for kept dumps and archives (`storage/backups`). |
| `backup_keep` | How many backups `db-backup` and `project-backup` keep per folder (env `SYNCOPS_BACKUP_KEEP`); empty keeps all. |
| `media.exclude` | Folder names `media-pull` and `media-backup` skip (`resized`, `thumb`, `temp`). |
| `backup.exclude` | Paths `project-backup` leaves out (`vendor`, `node_modules`, caches, `storage/temp`, `storage/backups`, `storage/app/resized`). |
| `local.mysql`, `local.mysqldump` | The database clients on your machine (env `SYNCOPS_MYSQL`, `SYNCOPS_MYSQLDUMP`), e.g. `C:\laragon\bin\mysql\mariadb-10.6.14-winx64\bin\mysql.exe` when they are not in the PATH. |

### Pinning the server's host key

Run `php artisan syncops:validate --connect`. It shows each server's fingerprint; add it to the `.env` file as
`SYNCOPS_<SERVER>_HOST_FINGERPRINT`. Compare it with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the
server, or with the fingerprint your SSH client showed when you first connected.

## Deploying

```bash
php artisan syncops:project-deploy production
```

On the server, the command:

1. checks that it is on `branch_prod` and that there are no uncommitted changes (bring them into Git with
   `project-pull` first);
2. puts the application into maintenance mode;
3. merges `origin/<branch_main>` into `branch_prod` and pushes it (or runs `git pull` when `branch_main` is `false`).
   If the merge fails, it is aborted and the server stays as it was;
4. runs `composer install` when `composer.lock` changed, then the migrations (`winter:up`). Composer re-creates
   Winter's `modules/` with its own file modes; changes that only affect file modes are reverted, so the server stays
   clean;
5. clears the cache and sets the ownership of `web_folders` to `web_user`, if configured;
6. brings the application out of maintenance mode – **always**, also after an error.

| Option | Description |
|---|---|
| `--fast`, `-f` | Without maintenance mode. |
| `--composer`, `-c` | Run `composer install` even if `composer.lock` didn't change. |
| `--migrate`, `-m` | Run the migrations even if Composer didn't run. |
| `--sudo`, `-x` | Run artisan and `chown` with `sudo -n` (never waits for a password). |
| `--dry-run` | Only list the steps and commands. |

## Bringing server changes into Git

Changes made on the server (theme files, static pages, …) block deployments until they are committed.

```bash
php artisan syncops:project-pull production            # commit on the server, push, merge locally
php artisan syncops:project-pull production --no-merge # commit and push only
php artisan syncops:project-pull production --pull     # also "git pull" on the server before pushing
```

On the server itself, `syncops:project-commit` commits and pushes everything (`--message=`, `--no-push`), e.g. daily
from the scheduler.

## Pulling a database

```bash
php artisan syncops:db-pull production
```

Dumps the server's database (with `mariadb-dump` or `mysqldump`, consistent and without locking), downloads it and
imports it into your local database – **replacing it**, so the command asks first (`--force` skips the question).

| Option | Description |
|---|---|
| `--tables=a,b` | Only these tables (overrides the connection's `tables`). |
| `--no-import`, `-i` | Only download the dump into `storage/backups/db-pull`. |
| `--keep` | Also keep the dump after importing it. |
| `--force` | Don't ask for confirmation. |

## Pulling media files

```bash
php artisan syncops:media-pull production
```

Downloads the files in the server's `storage/app` (media library, uploads) that are missing locally or have a
different size. Folders in `media.exclude` and dotfiles are skipped; local files are never deleted.
Options: `--no-overwrite` (`-o`) keeps existing local files, `--dry-run` only lists the files.

## Backups

The backup commands work on the machine they run on – usually the server, from the scheduler. Without a disk, the
backup stays in `storage/backups`; with a disk (e.g. `dropbox`), it is uploaded and the local copy is deleted
(`--no-delete` keeps it).

```bash
php artisan syncops:db-backup dropbox                 # database → dropbox:database/<timestamp>_<db>.sql.gz
php artisan syncops:media-backup dropbox              # storage/app → dropbox:storage/
php artisan syncops:project-backup dropbox --keep=4   # project → dropbox:project/<timestamp>.tar.gz
```

| Option | Commands | Description |
|---|---|---|
| `--folder=` | all | Folder locally and on the disk (`database`, `storage`, `project`). |
| `--keep=N` | db-backup, project-backup | Keep only the newest N backups in the folder (locally and on the disk); default `backup_keep`. |
| `--no-delete`, `-d` | db-backup, project-backup | Keep the local copy after uploading. |
| `--no-gzip`, `-g` | db-backup | Don't compress the dump. |
| `--connection=` | db-backup | The local database connection to back up. |
| `--exclude=a,b` | project-backup | More paths to leave out. |
| `--include-env` | project-backup | Include the `.env` file. It is left out by default, because it contains all secrets. |
| `--dry-run`, `-d`; `--log`, `-l` | media-backup | Only list the files; list each uploaded file. |

`media-backup` only uploads files that are new or have a different size, using one listing of the cloud folder.

### Scheduler

Register the backups in a plugin's `registerSchedule()` on the server, for example:

```php
public function registerSchedule($schedule): void
{
    $schedule->command('syncops:project-backup dropbox --keep=4')->weeklyOn(1, '01:00');
    $schedule->command('syncops:media-backup dropbox')->dailyAt('02:00');
    $schedule->command('syncops:db-backup dropbox --keep=14')->dailyAt('03:00');
    $schedule->command('syncops:project-commit')->dailyAt('04:00');
}
```

## Running commands on a server

```bash
php artisan syncops:remote-artisan production cache:clear
php artisan syncops:remote-artisan production queue:restart --force
php artisan syncops:remote-migrate production
```

Everything after the server name is passed to `php artisan` on the server, including options; the output appears
while the command runs, and the command's exit code is returned.

Winter loads no plugins when `migrate`, `winter:up`, `winter:update`, `winter:env`, `winter:version` or
`winter:manifest` is on the command line, so these can't be run with `remote-artisan`. Use
`syncops:remote-migrate` for the migrations and `syncops:remote-health` for the version.

## Checking a server

```bash
php artisan syncops:validate                 # configuration of all connections
php artisan syncops:validate --connect       # also log in, show the fingerprint, check the project, PHP and Composer
php artisan syncops:remote-health production # system, PHP, Composer, database client, Git, Winter version
php artisan syncops:remote-health production --full # also PHP extensions and the database login
```

## Upgrading from 1.x

Version 2.0 renames three commands and changes some defaults; see
[Upgrading from 1.x](CHANGELOG.md#upgrading-from-1x) in the changelog.

## Changelog

All notable changes are documented in the [CHANGELOG](CHANGELOG.md).

## Contributing

Please refer to the [CONTRIBUTING](CONTRIBUTING.md) guide for details on contributing to this project.

## Security

If you discover a security issue, please email <info@numencode.com> instead of using the issue tracker.

## Author

The **NumenCode.SyncOps** plugin is created and maintained by [Blaz Orazem](https://orazem.si/).
More NumenCode plugins: [numencode.com](https://www.numencode.com).

For inquiries, contact: <info@numencode.com>

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
