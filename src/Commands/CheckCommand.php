<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Pixelated\Streamline\Actions\CheckAvailableVersions;
use RuntimeException;

class CheckCommand extends Command
{

    public $signature = 'streamline:check
    {--force : Retrieve the most recent versions from GitHub}';

    public $description = 'Check for an available update';

    public function __construct(private readonly CheckAvailableVersions $availableVersions)
    {
        parent::__construct();
    }


    public function handle(): int
    {
        try {
            $nextVersion = $this->availableVersions->execute($this->option('force'));
            $this->info('Next available version: ' . $nextVersion);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
