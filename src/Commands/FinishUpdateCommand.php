<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Actions\Cleanup;
use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;

class FinishUpdateCommand extends Command
{
    protected $signature = 'streamline:finish-update';

    protected $description = 'Run this only after the update is complete. It really should only be run as part of the update pipeline.';

    protected $hidden = true;

    protected $isolated = true;

    public function handle(): void
    {
        app()->make(Cleanup::class)->__invoke(Config::get('streamline.work_temp_dir'));

        $this->call(InstantiateStreamlineUpdater::class);
    }
}
