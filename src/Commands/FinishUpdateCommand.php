<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Actions\Cleanup;
use Pixelated\Streamline\Actions\UncachedEnvironment;
use Pixelated\Streamline\Commands\Traits\OutputSubProcessCalls;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\InstalledVersionSet;

class FinishUpdateCommand extends Command
{
    use OutputSubProcessCalls;

    protected $signature = 'streamline:finish-update';

    protected $description = 'Run this only after the update is complete. It really should only be run as part of the update pipeline.';

    protected $hidden = true;

    protected $isolated = true;

    public function handle(UncachedEnvironment $env): void
    {
        $this->listenForSubProcessEvents();

        resolve(Cleanup::class)(Config::get('streamline.work_temp_dir'));

        if ($installedVersion = $env->get('STREAMLINE_APPLICATION_VERSION_INSTALLED')) {
            $this->info("Persisting the new version number ($installedVersion) to the cache.");
            Cache::put(CacheKeysEnum::INSTALLED_VERSION->value, $installedVersion);
            InstalledVersionSet::dispatch($installedVersion);
        }
    }
}
