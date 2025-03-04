<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Actions\SetInstalledVersionFromConfig;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\InstalledVersionSet;

class InitInstalledVersionCommand extends Command
{
    protected $signature = 'streamline:init-installed-version';
    protected $description = 'Initialise the installed version in cache';

    public function handle(): int
    {
        $currentVersion = Config::get('streamline.installed_version') ?: 'v0.0.0';
        Cache::put(CacheKeysEnum::INSTALLED_VERSION->value, $currentVersion);
        InstalledVersionSet::dispatch($currentVersion);

        $this->info('Installed version has been configured.');

        return self::SUCCESS;
    }
}
