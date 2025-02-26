<?php

namespace Pixelated\Streamline\Console\Commands;

use Illuminate\Console\Command;
use Pixelated\Streamline\Actions\Cleanup;
use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;

class FinishUpdateCommand extends Command
{
    protected $signature = 'streamline:finish-update
    {work-dir-to-delete : The directory to delete after the update.}';

    protected $description = 'Run this only after the update is complete. It really should only be run as part of the update pipeline.';

    protected $hidden = true;

    protected $isolated = true;

    public function handle(): void
    {
        app()->make(Cleanup::class)->__invoke($this->argument('work-dir-to-delete'));

        $this->call(InstantiateStreamlineUpdater::class);
    }
}
