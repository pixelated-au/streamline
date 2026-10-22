<?php

namespace Pixelated\Streamline\Updater;

use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;

class UpdateBuilder implements UpdateBuilderInterface
{
    public const string NO_VERSION_INSTALLED = 'v0.0.0';

    private string $basePath;

    private string $currentlyInstalledVersion;

    private ?string $composerPath = null;

    private ?string $requestedVersion = null;

    private ?string $versionToInstall = null;

    private bool $forceUpdate = false;

    private string $workTempDir;

    private string $downloadedArchivePath;

    private ?string $backupArchivePath = null;

    private ?string $verifiedChecksum = null;

    public function __construct()
    {
        $this->workTempDir = config('streamline.work_temp_dir');
    }

    public function setBasePath(string $basePath): UpdateBuilderInterface
    {
        $this->basePath = $basePath;

        return $this;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function setCurrentlyInstalledVersion(?string $version): UpdateBuilderInterface
    {
        // An absent installed version means nothing has been deployed by Streamline yet. Coercing
        // it here keeps the property (and every version_compare() downstream) a plain string.
        $this->currentlyInstalledVersion = $version ?: self::NO_VERSION_INSTALLED;

        return $this;
    }

    public function getCurrentlyInstalledVersion(): string
    {
        return $this->currentlyInstalledVersion;
    }

    public function setComposerPath(?string $path): UpdateBuilderInterface
    {
        if (!$path) {
            $path = 'composer';
        }
        $this->composerPath = $path;

        return $this;
    }

    public function getComposerPath(): string
    {
        return $this->composerPath;
    }

    public function setRequestedVersion(?string $version): UpdateBuilderInterface
    {
        $this->requestedVersion = $version;

        return $this;
    }

    public function getRequestedVersion(): ?string
    {
        return $this->requestedVersion;
    }

    public function setNextAvailableRepositoryVersion(?string $version = null): UpdateBuilderInterface
    {
        $this->versionToInstall = $version;

        return $this;
    }

    public function getNextAvailableRepositoryVersion(): ?string
    {
        return $this->versionToInstall;
    }

    public function forceUpdate(bool $doForce): UpdateBuilderInterface
    {
        $this->forceUpdate = $doForce;

        return $this;
    }

    public function isForceUpdate(): bool
    {
        return $this->forceUpdate;
    }

    public function getWorkTempDir(): string
    {
        return $this->workTempDir;
    }

    public function setBackupArchivePath(?string $path): UpdateBuilderInterface
    {
        $this->backupArchivePath = $path;

        return $this;
    }

    public function getBackupArchivePath(): ?string
    {
        return $this->backupArchivePath;
    }

    public function setDownloadedArchivePath(string $path): self
    {
        $this->downloadedArchivePath = $path;

        return $this;
    }

    public function getDownloadedArchivePath(): string
    {
        return $this->downloadedArchivePath;
    }

    public function setVerifiedChecksum(?string $checksum): UpdateBuilderInterface
    {
        $this->verifiedChecksum = $checksum;

        return $this;
    }

    public function getVerifiedChecksum(): ?string
    {
        return $this->verifiedChecksum;
    }
}
