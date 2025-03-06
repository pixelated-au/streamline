<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Actions\IsPreReleaseVersion;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use RuntimeException;

class VerifyVersion implements Pipe
{
    public function __construct(private readonly IsPreReleaseVersion $isPreReleaseVersion) {}

    public function __invoke(UpdateBuilderInterface $builder): ?UpdateBuilderInterface
    {
        $requestedVersion = $builder->getRequestedVersion();
        $currentVersion   = $builder->getCurrentlyInstalledVersion();
        $nextVersion      = $builder->getNextAvailableRepositoryVersion();
        $forceUpdate      = $builder->isForceUpdate();

        if ($requestedVersion) {
            CommandClassCallback::dispatch('info', "Changing deployment to version: $requestedVersion");

            $this->validateRequestedExists($requestedVersion);

            $this->checkPreRelease($forceUpdate, $requestedVersion);

            $this->isRequestedVersionSameAsCurrent($requestedVersion, $currentVersion, $forceUpdate);

            $this->isRequestedVersionOlderThanNextAvailableVersion($requestedVersion, $nextVersion, $forceUpdate);

            CommandClassCallback::dispatch('info', "Version: $requestedVersion will be installed");

            return $builder;
        }
        // User hasn't requested a specific version, this section assumes we'll use the next available version

        if (!$this->isUpdatedNeeded($nextVersion, $currentVersion, $forceUpdate)) {
            return null;
        }

        if (!$this->versionExists($nextVersion)) {
            throw new RuntimeException("Unexpected! The next available version: $nextVersion cannot be found.");
        }

        return $builder;
    }

    protected function isRequestedVersionSameAsCurrent(
        string $requestedVersion,
        string $currentVersion,
        bool $forceUpdate
    ): void {
        if (version_compare($requestedVersion, $currentVersion, '<=')) {
            $message = "Version $requestedVersion is not greater than the current version ($currentVersion)";

            if (!$forceUpdate) {
                throw new RuntimeException($message);
            }
            CommandClassCallback::dispatch('warn', "$message (Forced update)");
        }
    }

    protected function isRequestedVersionOlderThanNextAvailableVersion(
        string $requestedVersion,
        string $nextVersion,
        bool $forceUpdate
    ): void {
        if (version_compare($requestedVersion, $nextVersion, '<')) {
            $message = "Version $requestedVersion is not greater than the next available version ($nextVersion)";

            if (!$forceUpdate) {
                throw new RuntimeException($message);
            }
            CommandClassCallback::dispatch('warn', "$message (Forced update)");
        }
    }

    protected function checkPreRelease(bool $forceUpdate, string $requestedVersion): void
    {
        if ($forceUpdate) {
            return;
        }

        if ($this->isPreReleaseVersion->execute($requestedVersion)) {
            throw new RuntimeException("Version $requestedVersion is a pre-release version, use --force to install it.");
        }
    }

    protected function validateRequestedExists(string $requestedVersion): void
    {
        if (!$this->versionExists($requestedVersion)) {
            throw new RuntimeException("Version $requestedVersion is not a valid version!");
        }
    }

    protected function isUpdatedNeeded(string $nextVersion, string $currentVersion, bool $forceUpdate): bool
    {
        if (version_compare($nextVersion, $currentVersion, '<=')) {
            $message = "You are currently using the latest version ($nextVersion)";
            $message .= $forceUpdate ? ' (Forced update)' : '';
            CommandClassCallback::dispatch('warn', $message);

            return $forceUpdate;
        }

        CommandClassCallback::dispatch('info', "Deploying to next available version: $nextVersion");

        return true;
    }

    protected function versionExists(string $version): bool
    {
        return Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value)->contains($version);
    }
}
