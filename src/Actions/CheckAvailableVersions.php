<?php

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Events\NextAvailableVersionUpdated;
use RuntimeException;

class CheckAvailableVersions
{
    public function execute(bool $force = false, bool $ignorePreReleases = true): string
    {
        $availableVersions = Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value);
        if ($force || !$availableVersions || !count($availableVersions)) {
            CommandClassCallback::dispatch('warn', 'Checking for available versions...');
            Artisan::call('streamline:list');
        }

        $availableVersions = collect(Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value));
        $nextVersion = $this->getNextVersion($availableVersions, $ignorePreReleases);

        if (!$nextVersion) {
            throw new RuntimeException('The next available version could not be determined.');
        }
        Cache::forever(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value, $nextVersion);

        // Dispatch the NextAvailableVersionUpdated event
        NextAvailableVersionUpdated::dispatch($nextVersion);

        return $nextVersion;
    }

    protected function getNextVersion(?Collection $availableVersions, bool $ignorePreReleases): ?string
    {
        if (is_null($availableVersions)) {
            return null;
        }

        $nextVersion = $availableVersions[0] ?? null;
        if ($ignorePreReleases || Str::endsWith($nextVersion, ['a', 'b', 'alpha', 'beta'])) {
            // iterate $availableVersions until we find a non-prerelease version
            foreach ($availableVersions as $version) {
                if (!Str::endsWith($version, ['a', 'b', 'alpha', 'beta'])) {
                    $nextVersion = $version;
                    break;
                }
            }
        }

        return $nextVersion;
    }
}
