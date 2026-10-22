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

        /** @var iterable<int, string>|null $flaggedVersions */
        $flaggedVersions = Cache::get(CacheKeysEnum::PRE_RELEASE_VERSIONS->value);
        $nextVersion     = $this->getNextVersion($availableVersions, $ignorePreReleases, collect($flaggedVersions));

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
     * @param  Collection<int, string>  $flaggedVersions  versions GitHub flags as a pre-release
     */
    protected function getNextVersion(Collection $availableVersions, bool $ignorePreReleases, Collection $flaggedVersions): ?string
    {
        $nextVersion  = $availableVersions[0] ?? null;
        $isPreRelease = fn(string $version): bool => $flaggedVersions->contains($version)
            || $this->isPreReleaseVersion->execute($version);

        if ($nextVersion === null || !$ignorePreReleases || !$isPreRelease($nextVersion)) {
            return $nextVersion;
        }

        // Return the first stable version, or null when every available version is a pre-release.
        // Keeping the pre-release here would install one despite having been asked not to.
        foreach ($availableVersions as $version) {
            if (!$isPreRelease($version)) {
                return $version;
            }
        }

        return null;
    }
}
