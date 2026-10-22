<?php

/** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Events\NextAvailableVersionUpdated;
use RuntimeException;

class CheckAvailableVersions
{
    public function __construct(private readonly IsPreReleaseVersion $isPreReleaseVersion) {}

    public function execute(bool $force = false, bool $ignorePreReleases = true): string
    {
        $availableVersions = Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value);

        if ($force || !$availableVersions || !count($availableVersions)) {
            CommandClassCallback::dispatch('warn', 'Checking for available versions...');
            Artisan::call('streamline:list');
        }

        /** @var iterable<int, string>|null $cachedVersions */
        $cachedVersions    = Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value);
        $availableVersions = collect($cachedVersions);
        $nextVersion       = $this->getNextVersion($availableVersions, $ignorePreReleases);

        if (!$nextVersion) {
            throw new RuntimeException(
                $ignorePreReleases && $availableVersions->isNotEmpty()
                    ? 'The next available version could not be determined. Every available version is a pre-release; use --pre-releases to include them.'
                    : 'The next available version could not be determined.'
            );
        }
        Cache::forever(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value, $nextVersion);

        // Dispatch the NextAvailableVersionUpdated event
        NextAvailableVersionUpdated::dispatch($nextVersion);

        return $nextVersion;
    }

    /**
     * @param  Collection<int, string>  $availableVersions
     */
    protected function getNextVersion(Collection $availableVersions, bool $ignorePreReleases): ?string
    {
        $nextVersion = $availableVersions[0] ?? null;

        if ($nextVersion === null || !$ignorePreReleases || !$this->isPreReleaseVersion->execute($nextVersion)) {
            return $nextVersion;
        }

        // Return the first stable version, or null when every available version is a pre-release.
        // Keeping the pre-release here would install one despite having been asked not to.
        foreach ($availableVersions as $version) {
            if (!$this->isPreReleaseVersion->execute($version)) {
                return $version;
            }
        }

        return null;
    }
}
