# Backup Plugin

NumenCode Backup plugin for Winter CMS.

## Installation and setup

This package requires [Winter CMS](https://wintercms.com/) application.

Install the package with Composer:

```bash
composer require numencode/wn-backup-plugin
```

Run the command:

```bash
php artisan backup:up
```

Laravel includes a simple way to SSH into remote servers and run commands on remote servers. The SSH facade from
[LaravelCollective/Remote](https://laravelcollective.com/docs/6.x/remote) package provides the access point to
connecting to your remote servers and running commands.

The above command will create a new configuration file, located at `/config/remote.php`, that contains all the
options you need to configure your remote connections. The connections array contains a list of your servers keyed
by name. Simply populate the credentials in the connections array via your environment variables in the `.env` file.

> Note that the SSH can authenticate using either a password or an SSH key.

Beside the SSH credentials in the configuration file, you will notice some additional information under the
`backup` array of data. Here is an explanation for every single attribute from it:

| Data                    | Description                                                                    |
| :---------------------- | :----------------------------------------------------------------------------- |
| path                    | Path to the project on the remote server, e.g. `/var/www/yourdomain.com`       |
| branch                  | Name of the branch checked-out on the remote server, `prod` by default         |
| branch_main             | Name of the branch checked-out on the local/dev environment, `main` by default |
| permissions.root_user   | Superuser and group with full access, e.g. `root:root` or `pi:pi`              |
| permissions.web_user    | Web server user and group with limited access, e.g. `www-data:www-data`        |
| permissions.web_folders | Folder owned by web user, `storage,themes` by default                          |
| database.name           | Database name on the remote server                                             |
| database.username       | Database username on the remote server                                         |
| database.password       | Database password on the remote server                                         |
| database.tables*        | Tables viable for the `db:pull` command                                        |

*you can specify, which tables in the database you would like to sync with command `db:pull`. If no table names are
listed in this array, all the tables will be synchronized between environments. Since some settings stored in the
database are usually not identical across different environments, it makes sense to specify, which tables should
be synchronized, when `db:pull` command transmits the data from one database to the other.

### Environment variables example

This is an example for the environment variables in the local/dev `.env` file:

    REMOTE_PRODUCTION_HOST=123.456.789.10
    REMOTE_PRODUCTION_USERNAME=pi
    REMOTE_PRODUCTION_KEY=~/.ssh/id_rsa
    REMOTE_PRODUCTION_PATH=/var/www/domain.com
    REMOTE_PRODUCTION_BRANCH_MAIN=master
    REMOTE_PRODUCTION_ROOT_USER=pi:pi
    REMOTE_PRODUCTION_WEB_USER=www-data:www-data
    REMOTE_DB_DATABASE=project
    REMOTE_DB_USERNAME=user
    REMOTE_DB_PASSWORD=pass

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

| Command                           | Description                                                                                     |
| :-------------------------------- | :---------------------------------------------------------------------------------------------- |
| [db:backup](#db-backup)           | Create a database dump file and upload it to the cloud storage                                  |
| [db:pull](#db-pull)               | Transfer changes from the production database to the local/dev database                         |
| [media:backup](#media-backup)     | Upload all the media files to the cloud storage                                                 |
| [media:pull](#media-pull)         | Transfer all the media files from production to the local/dev environment via the cloud storage |
| [project:backup](#project-backup) | Create a compressed archive of all the project files and upload it to the cloud storage         |
| [project:pull](#project-pull)     | Transfer file changes from the production to the local/dev environment via Git                  |
| [project:commit](#project-pull)   | Add, commit and push file changes to Git                                                        |

---

<a name="db-backup"></a>
### Db:backup

The command creates a compressed SQL dump file of the project's default database. The name of the archive is the
current timestamp with the extension of `.sql.gz`. The timestamp format can be explicitly specified, although the
default format is `Y-m-d_H-i-s`. The command can also upload the file to the cloud storage, if an argument is provided.

This command is useful if it's set in the Scheduler to create a complete daily database backup and upload it to the
cloud storage.

#### Usage in CLI

```bash
php artisan db:backup
```

The command supports some optional arguments:
```bash
php artisan db:backup cloudName --folder=database --timestamp=d-m-Y --no-delete
```
- `cloudName` is the cloud storage where the archive is uploaded (defined in `/config/filesystems.php`)
- `--folder` is the name of the folder where the archive is stored (local and/or on the cloud storage)
- `--timestamp` is a date format used for naming the archive file (default: `Y-m-d_H-i-s`)
- `--no-delete` or `-d` prevents deleting the local archive file after it's uploaded to the cloud storage

#### Usage in Scheduler

```
$schedule->command('db:backup dropbox --folder=database')->dailyAt('02:00');
```

---

<a name="db-pull"></a>
### Db:pull

The command connects to a remote server with SSH, creates a database dump file, transfers it to the current working
environment (e.g. local/dev) and imports it into its database. The tables that are updated with the command are
specified in the list in the configuration file `/config/remote.php`, under the `backup.database.tables` section.
If no table is defined in this list, all the tables are taken into account and are updated.

The command is intended to be executed on a local/dev environment in order to update the local database with the
data from the production/staging database. Still, the command can also be executed on any other environment,
assuming that the appropriate credentials are set.

#### Usage in CLI

```bash
php artisan db:pull production
```
- where `production` is the remote server name (defined in `/config/remote.php`)

The command supports one optional argument:
```bash
php artisan db:pull production --no-import
```
- `--no-import` or `-i` prevents importing data automatically and leaves the database dump file in the project's root folder

---

<a name="media-backup"></a>
### Media:backup

The command uploads all the media files from the folder `storage/app/` to the cloud storage. All `.gitignore` files
and `/thumb/` folders are excluded from the upload by default.

This command is useful if it's set in the Scheduler to create a complete daily media backup and upload it to the
cloud storage.

#### Usage in CLI

```bash
php artisan media:backup cloudName
```
- where `cloudName` is the cloud storage where the media files are uploaded (defined in `/config/filesystems.php`)

The command supports one optional argument:
```bash
php artisan media:backup cloudName folder
```
- `folder` is the name of the folder on the cloud storage where the media files are uploaded, the default is `storage/`

#### Usage in Scheduler

```
$schedule->command('media:backup cloudName')->dailyAt('03:00');
```

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
- where `production` is the remote server name (defined in `/config/remote.php`)
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
explicitly specified, although the default format is `Y-m-d_H-i-s`. The command can also upload the file to the
cloud storage, if an argument is provided. You can exclude explicitly selected folders from the archive.

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

<a name="project-pull"></a>
### Project:pull

The command adds and commits changes on the production environment, pushes them to the git repository and then
fetches and merges them on the local/dev environment.

This command is very useful for quickly retrieving content changes that have been made on the production environment.
Since Winter CMS stores pages, layouts, contents, etc. in static `*.htm` files, the best way to fetch these changes
is by pushing them to the git repository and merging them locally.

#### Usage in CLI

```bash
php artisan project:pull production
```
- where `production` is the remote server name (defined in `/config/remote.php`)

The command supports some optional arguments:
```bash
php artisan project:pull production --pull --no-merge
```
- where `--pull` or `-p` is an optional argument which executes git pull command before git push
- where `--no-merge` or `-m` is an optional argument which does not merge changes automatically

### Project:deploy

The command puts the app into maintenance mode, clears all the cache, pulls the changes from the git repository -
from the branch `main` (by default), merges them into branch `prod` (by default), rebuilds the cache and turns off
the maintenance mode. If there are changes present in `composer.lock` file, the packages are updated automatically
and the migrations can be run with the parameter `-m`.

#### Usage in CLI

```bash
php artisan project:deploy production
```
- where `production` is the remote server name (defined in `/config/remote.php`)

The command supports some optional arguments:
```bash
php artisan project:deploy production --fast --composer --migrate --sudo
```
- where `--fast` or `-f` is an optional argument which deploys without clearing the cache
- where `--composer` or `-c` is an optional argument which forces Composer install
- where `--migrate` or `-m` is an optional argument which runs migrations (`php artisan winter:up`)
- where `--sudo` or `-x` is an optional argument which forces the superuser (`sudo`) usage

---

<a name="project-commit"></a>
### Project:commit

The command adds and commits all the changes to the git repository with a default message of `Server changes`.
Changes are committed to the branch that is currently checked-out.

This command is useful when it's set in the scheduler so that it adds and commits changes every day for example.

#### Usage in CLI

```bash
php artisan project:commit "This is a custom commit message"
```

The commit message argument is optional, and it defaults to `"Server changes"` message.

#### Usage in Scheduler

```
$schedule->command('project:commit')->daily()->at('01:00');
```

---

## Recommended Scheduler settings

Here are the recommended entries for the Scheduler:
- create a complete backup every monday at 1 am
- create a backup of all media files every day at 2 am
- create a backup of the database every day at 3 am
- commit changes from the production environment every day at 4 am

```
$schedule->command('project:backup dropbox --folder=files')->weeklyOn(1, '01:00');
$schedule->command('media:backup dropbox')->daily()->at('02:00');
$schedule->command('db:backup dropbox')->daily()->at('03:00');
$schedule->command('project:commit')->daily()->at('04:00');
```

## Dropbox setup

1. Require the Dropbox adapter package with Composer
```bash
composer require renatio/dropboxadapter-plugin
```

2. Login to your Dropbox account and configure API service on https://www.dropbox.com/developers/apps

3. Once you have the authorization token, edit `/config/filesystems.php` and add the adapter
```bash
'dropbox' => [
    'driver'             => 'dropbox',
    'authorizationToken' => env('DROPBOX_AUTH_TOKEN', ''),
],
```

4. Add your authorization token to the `.env` file
```bash
DROPBOX_AUTH_TOKEN=yourTokenHere
```

5. You're all set, you can start using Dropbox as your cloud storage.

# Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

# Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

# Security

If you discover any security-related issues, please email info@numencode.com instead of using the issue tracker.

# Author

**NumenCode.Widgets** plugin was created by and is maintained by [Blaz Orazem](https://www.orazem.si/).

Please write an email to info@numencode.com about all the things concerning this project.

Follow [@blazorazem](https://twitter.com/blazorazem) on Twitter.

# License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

[![MIT License](https://img.shields.io/github/license/numencode/wn-backup-plugin?label=License&color=blue&style=flat-square&cacheSeconds=600)](https://github.com/numencode/wn-backup-plugin/blob/main/LICENSE.md)
