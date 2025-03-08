<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Pixelated\Streamline\Actions\GetAvailableVersions;
use Pixelated\Streamline\Commands\Traits\GitHubApi;
use Pixelated\Streamline\Commands\Traits\OutputSubProcessCalls;

class ListCommand extends Command
{
    use GitHubApi;
    use OutputSubProcessCalls;

    public $signature = 'streamline:list';

    public $description = 'Retrieves the available updates from GitHub and stores them in the cache';

    public function handle(GetAvailableVersions $getAvailableVersions): int
    {
        $this->listenForSubProcessEvents();

        $this->comment('Pulling down available versions...');
        $versions = $getAvailableVersions->execute();
        $this->newLine();
        $this->info("Available versions: $versions");

        return self::SUCCESS;
    }
}
