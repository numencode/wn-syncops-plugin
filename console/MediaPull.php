<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Support\Facades\Storage;
use NumenCode\SyncOps\Classes\RemoteExecutor;

class MediaPull extends RemoteCommand
{
    protected $signature = 'syncops:media-pull
        {server   : The name of the remote server}
        {cloud    : The name of the cloud storage disk to upload media files to}
        {folder?  : The target folder name in the cloud storage (default: "storage")}
        {--x|sudo : Force super user (sudo) on the remote server}';

    protected $description = 'Runs syncops:media-push on the remote server, which uploads the media files to the cloud storage and downloads the media files to the local storage from there.';

    public function handle()
    {
        $cloud = $this->argument('cloud');
        $folder = $this->resolveFolderName($this->argument('folder'));
        $useSudo = $this->option('sudo') ? 'sudo ' : '';

        $this->comment("Connecting to remote server '{$this->argument('server')}'...");
        $executor = new RemoteExecutor($this->argument('server'));

        $this->line("Executing syncops:media-push on the remote server '{$this->argument('server')}'...");
        $remoteCommands[] = ["{$useSudo}php artisan syncops:media-push {$cloud} {$folder}"];
        $result = $executor->ssh->runAndPrint($remoteCommands);

        if (!str_contains($result, 'files have been successfully uploaded')) {
            $this->error("✘ An error occurred while uploading files to the cloud storage '{$cloud}' from remote server '{$this->argument('server')}'.");
            return self::FAILURE;
        }

        $localStorage = Storage::disk('local');
        $cloudStorage = Storage::disk($cloud);

        $files = array_filter($cloudStorage->allFiles(), function ($file) use ($folder) {
            return starts_with($file, $folder);
        });

        $this->line("Downloading " . count($files) . " files from the cloud storage..." . PHP_EOL);

        $bar = $this->output->createProgressBar(count($files));

        foreach ($files as $file) {
            $bar->advance();

            $localStorageFile = ltrim($file, $folder);

            if ($localStorage->exists($localStorageFile)) {
                if ($localStorage->size($localStorageFile) == $cloudStorage->size($file)) {
                    continue;
                }
            }

            $localStorage->put($localStorageFile, $cloudStorage->get($file));
        }

        $bar->finish();

        $this->info(PHP_EOL . "✔ All files successfully downloaded to the local storage.");
    }

    protected function resolveFolderName($folderName = null)
    {
        return $folderName ? rtrim($folderName, '/') . '/' : 'storage/';
    }


    public function handleDEPRECATED()
    {
        if (!$this->sshConnect()) {
            return $this->error("An error occurred while connecting with SSH.");
        }

        if ($this->option('sudo')) {
            $this->sudo = 'sudo ';
        }

        $cloud = $this->argument('cloud');
        $folder = $this->resolveFolderName($this->argument('folder'));

        $this->info(PHP_EOL . "Now connected to the {$this->argument('server')} server." . PHP_EOL);
        $this->line("Executing media:backup command...");

        $result = $this->sshRunAndPrint([$this->sudo . 'php artisan media:backup ' . $cloud . ' ' . $folder]);

        if (!str_contains($result, 'files successfully uploaded')) {
            $this->error(PHP_EOL . "An error occurred while uploading files to the cloud storage.");

            return false;
        }

        $localStorage = Storage::disk('local');
        $cloudStorage = Storage::disk($cloud);

        $files = array_filter($cloudStorage->allFiles(), function ($file) use ($folder) {
            return starts_with($file, $folder);
        });

        $this->info(PHP_EOL . "Switched back to local environment." . PHP_EOL);

        $this->line("Downloading " . count($files) . " files from the cloud storage..." . PHP_EOL);

        $bar = $this->output->createProgressBar(count($files));

        foreach ($files as $file) {
            $bar->advance();

            $localStorageFile = ltrim($file, $folder);

            if ($localStorage->exists($localStorageFile)) {
                if ($localStorage->size($localStorageFile) == $cloudStorage->size($file)) {
                    continue;
                }
            }

            $localStorage->put($localStorageFile, $cloudStorage->get($file));
        }

        $bar->finish();

        $this->line(PHP_EOL);
        $this->alert('All files successfully downloaded to the local storage.');
    }
}
