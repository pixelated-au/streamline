<?php

namespace Pixelated\Streamline\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Pixelated\Streamline\Services\GitHubApi withApiUrl(string $path)
 * @method static \Pixelated\Streamline\Services\GitHubApi withWebUrl(string $path)
 * @method static \Pixelated\Streamline\Services\GitHubApi withDownloadPath(?string $path)
 * @method static \Pixelated\Streamline\Services\GitHubApi withProgressCallback(?\Pixelated\Streamline\Actions\ProgressMeter $callback)
 * @method static \Pixelated\Streamline\Services\GitHubApi withQueryParams(array<string, mixed> $params)
 * @method static \Pixelated\Streamline\Services\GitHubApi progressMessage(string $message)
 * @method static \Illuminate\Http\Client\Response get()
 * @method static \Illuminate\Support\Collection<int, mixed> paginate()
 *
 * @see \Pixelated\Streamline\Services\GitHubApi
 */
class GitHubApi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pixelated\Streamline\Services\GitHubApi::class;
    }
}
