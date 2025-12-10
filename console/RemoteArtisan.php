<?php namespace NumenCode\SyncOps\Console;

use Illuminate\Console\Command;
use NumenCode\SyncOps\Traits\RunsRemoteCommands;

class RemoteArtisan extends Command
{
    use RunsRemoteCommands;

    protected $signature = 'syncops:remote-artisan
        {server          : The name of the remote server connection as defined in syncops.php}
        {artisanCommand* : The artisan command and arguments to run on the remote server}';

    protected $description = 'Run a "php artisan" command on a remote server and stream the output locally.';

    public function handle(): int
    {
        $this->newLine();

        $server = $this->argument('server');
        $artisanParts = (array) $this->argument('artisanCommand');

        if (empty($artisanParts)) {
            $this->error('✘ No artisan command provided. Please specify the artisan sub-command to run.');
            return self::FAILURE;
        }

        // Build the full command: php artisan <args...>
        $commandParts = array_merge(['php', 'artisan'], $artisanParts);
        $prettyCommand = 'php artisan ' . implode(' ', $artisanParts);

        try {
            $this->line("Connecting to remote server '{$server}'...");
            $this->newLine();

            $this->line("Running remote artisan command:");
            $this->comment($prettyCommand);
            $this->newLine();

            // This will echo the remote output directly (via SshExecutor::runAndPrint)
            $this->runRemoteAndPrint($server, [$commandParts]);

            $this->newLine();
            $this->info('✔ Remote artisan command executed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("✘ Failed to run remote artisan command on server '{$server}':");
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
