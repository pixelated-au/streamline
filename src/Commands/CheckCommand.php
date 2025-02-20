<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Pixelated\Streamline\Actions\CheckAvailableVersions;
use Pixelated\Streamline\Commands\Traits\GitHubApi;
use Pixelated\Streamline\Commands\Traits\OutputSubProcessCalls;
use RuntimeException;

class CheckCommand extends Command
{
    use GitHubApi;
    use OutputSubProcessCalls;

    public $signature = 'streamline:check
    {--pre-releases : Include alpha and beta pre-release versions}
    {--force : Retrieve the most recent versions from GitHub}';

    public $description = 'Check for an available update';

    public function handle(CheckAvailableVersions $availableVersions): int
    {
        $this->setGitHubAuthToken();
        $this->listenForSubProcessEvents();
        try {
            $nextVersion = $availableVersions->execute($this->option('force'), $this->option('pre-releases'));
            $this->info('Next available version: ' . $nextVersion);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
