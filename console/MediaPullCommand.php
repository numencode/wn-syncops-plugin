<?php namespace NumenCode\Backup\Console;

use Illuminate\Support\Facades\Storage;

class MediaPullCommand extends RemoteCommand
{
    protected $signature = 'media:pull
        {server     : The name of the remote server}
        {cloudName  : Cloud storage where the media files are uploaded}
        {folder?    : The name of the folder on the cloud storage where the media files are stored (default: storage)}
        {--x|--sudo : Force super user (sudo) on the remote server}';

    protected $description = 'Run media:backup command on the remote server and then download all the media files from the cloud storage to the local storage.';

    protected $sudo;

    public function handle()
    {
        if (!$this->sshConnect()) {
            return $this->error('An error occurred while connecting with SSH.');
        }

        if ($this->option('sudo')) {
            $this->sudo = 'sudo ';
        }

        $cloud = $this->argument('cloudName');
        $folder = $this->resolveFolderName($this->argument('folder'));

        $this->info(PHP_EOL . "Now connected to the {$this->argument('server')} environment." . PHP_EOL);
        $this->line('Executing media:backup command...');

        $result = $this->sshRunAndPrint([$this->sudo . 'php artisan media:backup ' . $cloud . ' ' . $folder]);

        if (!str_contains($result, 'files successfully uploaded')) {
            $this->error(PHP_EOL . 'An error occurred while uploading files to the cloud storage.');

            return false;
        }

        $localStorage = Storage::disk('local');
        $cloudStorage = Storage::disk($cloud);

        $files = array_filter($cloudStorage->allFiles(), function ($file) use ($folder) {
            return starts_with($file, $folder);
        });

        $this->info(PHP_EOL . 'Switched back to local environment.' . PHP_EOL);

        $this->line('Downloading ' . count($files) . ' files from the cloud storage...' . PHP_EOL);

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

    protected function resolveFolderName($folderName = null)
    {
        return $folderName ? rtrim($folderName, '/') . '/' : 'storage/';
    }
}
