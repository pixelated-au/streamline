<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use RuntimeException;

class VerifyVersion implements Pipe
{
    public function __invoke(UpdateBuilderInterface $builder): ?UpdateBuilderInterface
    {
        $requestedVersion = $builder->getRequestedVersion();
        $nextVersion = $builder->getNextAvailableRepositoryVersion();
        $forceUpdate = $builder->isForceUpdate();

        if ($requestedVersion) {
            CommandClassCallback::dispatch('info', "Changing deployment to version: $requestedVersion");
            if (!$this->versionExists($requestedVersion)) {
                throw new RuntimeException("Version $requestedVersion is not a valid version!");
            }

            if (version_compare($requestedVersion, $nextVersion, '<')) {
                $message = "Version $requestedVersion is not greater than the current version ($nextVersion)";
                if (!$forceUpdate) {
                    throw new RuntimeException($message);
                }
                CommandClassCallback::dispatch('warn', "$message (Forced update)");
            }
        } elseif (version_compare($nextVersion, config('streamline.installed_version'), '<=')) {
            $message = "You are currently using the latest version ($nextVersion)"
                . ($forceUpdate ? ' (Forced update)' : '');
            CommandClassCallback::dispatch('warn', $message);
            if (!$forceUpdate) {
                return null;
            }
        } else {
            CommandClassCallback::dispatch('info', "Deploying to next available version: $nextVersion");
        }

        if (!$requestedVersion && !$this->versionExists($nextVersion)) {
            throw new RuntimeException("Unexpected! The next available version: $nextVersion cannot be found.");
        }

        return $builder;
    }

    protected function versionExists(string $version): bool
    {
        return Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value)
            ->contains($version);
    }
}
