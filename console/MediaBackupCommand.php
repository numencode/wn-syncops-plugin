<?php namespace NumenCode\Backup\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MediaBackupCommand extends Command
{
    protected $signature = 'media:backup
        {cloudName : Cloud storage where the media files are uploaded}
        {folder?   : The name of the folder on the cloud storage where the media files are stored (default: storage)}';

    protected $description = 'Upload all the media files to the cloud storage.';

    public function handle()
    {
        $cloudStorage = Storage::disk($this->argument('cloudName'));

        $files = array_filter(Storage::allFiles(), function ($file) {
            return basename($file) != '.gitignore' && !stristr($file, '/thumb/');
        });

        $this->line(PHP_EOL . 'Uploading ' . count($files) . ' files to the cloud storage...' . PHP_EOL);

        $bar = $this->output->createProgressBar(count($files));

        foreach ($files as $file) {
            $bar->advance();

            $storageFile = $this->resolveFolderName($this->argument('folder')) . $file;

            if ($cloudStorage->exists($storageFile) && ($cloudStorage->size($storageFile) == Storage::size($file))) {
                continue;
            }

            $cloudStorage->put($storageFile, Storage::get($file));
        }

        $bar->finish();

        $this->line(PHP_EOL);
        $this->alert('All media files successfully uploaded to the cloud storage.');
    }

    protected function resolveFolderName($folderName = null)
    {
        return $folderName ? rtrim($folderName, '/') . '/' : 'storage/';
    }
}
