<?php /** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Facades;
use Pixelated\Streamline\Services\GitHubApi;

class GetAvailableVersions
{
    public function __construct(private readonly ProgressMeter $meter)
    {
    }


    public function execute(): string
    {
        $versions = Facades\GitHubApi::withApiUrl(GitHubApi::GITHUB_RELEASES_PATH)
            ->withProgressCallback($this->meter)
            ->paginate()
            ->pluck('tag_name');

        Cache::forever(CacheKeysEnum::AVAILABLE_VERSIONS->value, $versions);

        return $versions->implode(', ');
    }
}
