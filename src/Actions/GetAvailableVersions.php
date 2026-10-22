<?php

/** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\AvailableVersionsUpdated;
use Pixelated\Streamline\Facades;
use Pixelated\Streamline\Services\GitHubApi;

class GetAvailableVersions
{
    public function __construct(private readonly ProgressMeter $meter) {}

    public function execute(): string
    {
        $releases = Facades\GitHubApi::withApiUrl(GitHubApi::GITHUB_RELEASES_PATH)
            ->withProgressCallback($this->meter)
            ->progressMessage('Retrieving versions...')
            ->paginate();

        $versions = $releases->pluck('tag_name');

        Cache::forever(CacheKeysEnum::AVAILABLE_VERSIONS->value, $versions);
        // Releases flagged as "pre-release" on GitHub are never auto-installed, whatever their tag looks like
        Cache::forever(
            CacheKeysEnum::PRE_RELEASE_VERSIONS->value,
            $releases->filter(fn($release) => is_array($release) && ($release['prerelease'] ?? false) === true)
                ->pluck('tag_name')
                ->values()
        );
        // Dispatch the AvailableVersionsUpdated event
        AvailableVersionsUpdated::dispatch($versions);

        return $versions->implode(', ');
    }
}
