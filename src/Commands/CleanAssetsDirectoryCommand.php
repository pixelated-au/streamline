<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Pixelated\Streamline\Commands\Traits\OutputSubProcessCalls;
use Pixelated\Streamline\Services\CleanUpAssets;

class CleanAssetsDirectoryCommand extends Command
{
    use OutputSubProcessCalls;

    protected $signature = 'streamline:clean-assets
    {--revisions= : Maximum number of revisions to retain}
    {--force : Force clean the directory, even if there are no revisions to remove}';

    protected $description = 'Tidy up the front-end build assets directory. Default values are configured in config/streamline.php';

    public function handle(CleanUpAssets $cleanUpAssets): int
    {
        $force     = $this->option('force');
        $revisions = $this->option('revisions');

        $this->listenForSubProcessEvents();

        // Only a plain positive integer (>= 1) is accepted. Zero would delete every revision, including the live build.
        if ($revisions !== null && !preg_match('/^[1-9]\d*$/', $revisions)) {
            $this->error('Invalid number of revisions. Please provide a positive integer.');

            return self::FAILURE;
        }

        $this->warn('This command will remove old revisions of the front-end build assets directory. It may be wise to do a backup of your assets first. Proceed with caution!');

        if (!$force) {
            $response = $this->confirm('Are you sure you want to clean the assets directory?');

            if (!$response) {
                $this->info('Cleaning aborted.');

                return self::FAILURE;
            }
        }

        $this->info('Cleaning up the front-end build assets directory...');

        if ($revisions !== null) {
            $this->info("Retaining $revisions revisions");
        }

        $cleanUpAssets->run(is_string($revisions) ? (int) $revisions : null);

        return self::SUCCESS;
    }
}
