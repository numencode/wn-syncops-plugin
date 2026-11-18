# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.1] - 2026-11-18

### Fixed

- Packagist's metadata for 2.0.0 referred to a different commit than the git tag. This release has the same code as
  2.0.0 and is the version to install.

## [2.0.0] - 2026-11-08

A security and reliability rewrite. It contains breaking changes – see [Upgrading from 1.x](#upgrading-from-1x).

### Security

- **Database passwords are no longer passed on command lines** (`mysqldump -p…`, `mysql -p…`), where other users
  of the server could see them in the process list. Credentials are written to a temporary option file in a private
  directory (`--defaults-extra-file`) and removed afterwards. Unescaped passwords could also break the local import
  and backup commands or run shell code.
- **Secrets are no longer printed.** A failed remote command printed the full command, for a database dump including
  the password. Errors now name the step and show its error output, and every configured secret is replaced with
  `***`.
- **Host key verification:** with `ssh.host_fingerprint`, SyncOps refuses to connect to a server with a different
  host key (man-in-the-middle protection). Without it, a warning shows the server's fingerprint.
- **Temporary files are private:** database dumps are created in a private temporary directory on the server (they
  were written into the project root, often the web root) and in `storage/temp` locally, and always removed.
- **`project-backup` leaves out the `.env` file** by default (`--include-env` adds it).

### Added

- `syncops:remote-migrate` runs `winter:up` on a server.
- Settings: `ssh.key_passphrase` (encrypted keys), `ssh.host_fingerprint`, `ssh.timeout`, `project.php`,
  `project.composer`, `project.temp_path`, `database.host`/`port`, `backup_path`, `backup_keep`, `media.exclude`,
  `backup.exclude`, `local.mysql`/`local.mysqldump`.
- `project-deploy --dry-run`, `media-pull --dry-run`.
- `db-pull --tables=`, `--keep` and `--force`; it asks before replacing the local database.
- `--keep=N` for `db-backup` and `project-backup`: keep only the newest N backups (locally and in the cloud).
- `remote-artisan` passes options on (`cache:clear --force`), shows the output live and returns the remote exit code.
- `validate --connect` shows the host key fingerprint and checks the project, PHP and Composer on the server.

### Changed

- **Renamed commands:** `syncops:db-push` → `syncops:db-backup`, `syncops:media-push` → `syncops:media-backup`,
  `syncops:project-push` → `syncops:project-commit`.
- **Requires PHP 8.2+.** Tested with Winter CMS 1.2.15 on PHP 8.2–8.5.
- **`project-deploy`:**
  - Maintenance mode is always ended, also when a step fails.
  - A failed merge is aborted (`git merge --abort`) and the deployment stops; the server stays as it was.
  - It checks that the server is on `branch_prod` and clean before changing anything.
  - Composer runs when `composer.lock` changed (or with `--composer`), with `--no-interaction --prefer-dist
    --optimize-autoloader`; the migrations run after Composer (or with `--migrate`).
  - File mode changes made by Composer in tracked files (Winter's `modules/`) are reverted.
  - `--sudo` uses `sudo -n`; Composer and migration output is shown live.
- **`db-pull`:** consistent dumps without locks (`--single-transaction --quick --no-tablespaces`), `mariadb-dump`
  where available, streamed decompression, and the full local connection (host, port, socket). The dump is always
  compressed (the `--no-gzip` option is removed); `--no-import` keeps it in `storage/backups/db-pull`.
- **`media-pull`** lists the server's files with one request instead of several per file; **`media-backup`** lists
  the cloud folder once and uploads files as streams. Both skip the folders in `media.exclude` (now also `resized`)
  and all dotfiles (`.gitignore` files belong to the Git repository, not to the media).
- **Backups** are kept in `storage/backups` (was the project root or `backup/`); `db-backup` uses the folder
  `database` and `project-backup` the folder `project` by default.
- One SSH login per command (was two); remote commands have no 10-second limit any more.
- All output goes through the console (quiet and verbose modes work).

### Fixed

- `db-pull` imported into a database named `<database>test`.
- `project-deploy` left the site in maintenance mode when any step failed, and the conflict handling never ran.
- `project-deploy`, `remote-artisan` and `remote-health` always used `php` and `composer`, which fail on servers
  where the default PHP is another version (shell aliases don't apply over SSH).
- Remote commands running longer than 10 seconds (Composer, migrations, large dumps) were cut off, and every later
  command on the connection failed.
- `remote-artisan` could not pass options (`--force`), and `migrate`/`winter:up` could not run at all.
- A project path starting with `~` didn't work; remote error messages were empty.
- `validate` always warned about missing database credentials.
- `db-pull` loaded the whole dump and `media-backup` whole files into memory.

### Removed

- The `RemoteExecutor`, `SshExecutor`, `SftpExecutor` and `MysqlCommandBuilder` classes and the
  `RunsLocalCommands`/`RunsRemoteCommands` traits (replaced by `RemoteServer`, `DatabaseDump` and the
  `SyncOpsCommand` base class).
- The global `format_path()` helper.
- Support for PHP 8.1, which has reached end of life.

### Upgrading from 1.x

1. **PHP 8.2** or newer is required.
2. **Renamed commands** – update scripts and the scheduler: `syncops:db-push` → `syncops:db-backup`,
   `syncops:media-push` → `syncops:media-backup`, `syncops:project-push` → `syncops:project-commit`.
3. **Configuration** – your `config/syncops.php` keeps working; new settings use their defaults. Recommended:
   - pin each server's host key: run `php artisan syncops:validate --connect` and add the shown fingerprint as
     `ssh.host_fingerprint` (`SYNCOPS_<SERVER>_HOST_FINGERPRINT`);
   - if the server's default PHP is not the project's version, set `project.php` and `project.composer`
     (e.g. `php8.4` and `php8.4 /usr/bin/composer`);
   - compare your copy with the plugin's `config/syncops.php` to add the new settings with their comments.
4. **Backups** are stored in `storage/backups` instead of the project root or `backup/`, and in the folders
   `database` and `project` on the cloud disk (use `--folder=` for other folders). `project-backup` no longer
   includes `.env`; add `--include-env` if you need it.
5. **`db-pull`** asks before replacing the local database; add `--force` in scripts. `--no-gzip` was removed.

## [1.1.0] - 2025-12-12

### Added

- `syncops:remote-artisan` for running `php artisan` commands on a remote server.
- `syncops:validate` for validating the connections in `config/syncops.php` (with an optional `--connect` check).
- `syncops:remote-health` for inspecting a remote server (system, PHP, database, Git, Laravel and Winter CMS).

### Changed

- Refactored the remote helpers (`RemoteExecutor`, `SshExecutor`, `SftpExecutor`).
- Centralized MySQL/MariaDB dump and import command generation.

### Fixed

- More robust cleanup of temporary local and remote files.
- Edge cases in remote database dumps, Git operations and media synchronization.

## [1.0.0] - 2025-11-04

### Added

- Initial release: deployment via Git, database and media synchronization over SSH/SFTP, project backups and cloud
  storage uploads, permissions handling and `config/syncops.php`. Compatible with Winter CMS 1.2.8+ and PHP 8.1+.

[Unreleased]: https://github.com/numencode/wn-syncops-plugin/compare/v2.0.1...HEAD
[2.0.1]: https://github.com/numencode/wn-syncops-plugin/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/numencode/wn-syncops-plugin/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/numencode/wn-syncops-plugin/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/numencode/wn-syncops-plugin/releases/tag/v1.0.0
