<?php

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\CommandClassCallback;
use RuntimeException;

class CheckAvailableVersions
{
    public function execute(bool $force = false): string
    {
        $nextVersion = Cache::get(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value);
        if (! $nextVersion || $force) {
            Event::dispatch(new CommandClassCallback('warn', 'Checking for available versions...'));
            Event::dispatch(new CommandClassCallback('call', 'streamline:list'));

            $availableVersions = Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value);
            $nextVersion = $this->getNextVersion($availableVersions);

            if (! $nextVersion) {
                throw new RuntimeException("Well, this isn't expected! The query to the GitHub repository" .
                    ' appeared successful but no versions have been stored. Please check your Laravel cache or' .
                    ' confirm the repository settings are correct in /config/streamline.php');
            }
            Cache::forever(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value, $nextVersion);
        }

        return $nextVersion;
    }

    protected function getNextVersion(mixed $availableVersions): mixed
    {
        $nextVersion = $availableVersions[0] ?? null;
        if (Str::endsWith($nextVersion, ['a', 'b', 'alpha', 'beta'])) {
            // iterate $availableVersions until we find a non-prerelease version
            foreach ($availableVersions as $version) {
                if (! Str::endsWith($version, ['a', 'b', 'alpha', 'beta'])) {
                    $nextVersion = $version;
                    break;
                }
            }
        }

        return $nextVersion;
    }
}
