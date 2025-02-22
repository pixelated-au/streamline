<?php

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\InstalledVersionSet;

class SetInstalledVersionFromConfig
{
    public function execute(): void
    {
        $currentVersion = Config::get('streamline.installed_version', 'v0.0.0');

        Cache::put(CacheKeysEnum::INSTALLED_VERSION->value, $currentVersion);

        event(new InstalledVersionSet($currentVersion));
    }
}
