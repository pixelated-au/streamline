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
    {--pre-releases : Include pre-release versions (any version with a suffix such as -rc1, -alpha.1, -beta.2, or flagged as a pre-release on GitHub)}
    {--force : Retrieve the most recent versions from GitHub}';

    public $description = 'Check for an available update';

    public function handle(CheckAvailableVersions $availableVersions): int
    {
        // the --gh-token option is applied once by the GitHubApi trait's execute()
        $this->listenForSubProcessEvents();

        try {
            $nextVersion = $availableVersions->execute($this->option('force'), !$this->option('pre-releases'));
            $this->info('Next available version: ' . $nextVersion);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
