<?php namespace NumenCode\Backup\Console;

use File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MediaBackupCommand extends Command
{
    protected $signature = 'media:backup
        {cloudName : Cloud storage where the media files are uploaded}
        {folder?   : The name of the folder on the cloud storage where the media files are stored}';

    protected $description = 'Upload all the media files to the cloud storage.';

    public function handle()
    {
        $folder = $this->prepareFolder($this->option('folder'));
        $cloudStorage = Storage::disk($this->argument('cloud'));

        $files = array_filter(Storage::allFiles(), function ($file) {
            return basename($file) != '.gitignore' && !stristr($file, '/thumb/');
        });

        $this->line('');
        $this->question('Uploading ' . count($files) . ' files to the cloud storage...');

        $bar = $this->output->createProgressBar(count($files));

        foreach ($files as $file) {
            $bar->advance();

            $storageFile = $folder . $file;

            if ($cloudStorage->exists($storageFile) && ($cloudStorage->size($storageFile) == Storage::size($file))) {
                continue;
            }

            $cloudStorage->put($storageFile, Storage::get($file));
        }

        $bar->finish();

        $this->alert('All media files successfully uploaded to the cloud storage.');
    }

    protected function prepareFolder($folderName = null)
    {
        $folderName = $folderName ? rtrim($folderName, '/') . '/' : null;

        if ($folderName && !File::isDirectory($folderName)) {
            File::makeDirectory($folderName, 0777, true, true);
        }

        return $folderName;
    }
}
