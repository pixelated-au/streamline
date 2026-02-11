<?php

namespace Pixelated\Streamline\Updater;

use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;

class UpdateBuilder implements UpdateBuilderInterface
{
    private string $basePath;

    private string $currentlyInstalledVersion;

    private ?string $composerPath = null;

    private ?string $requestedVersion = null;

    private ?string $versionToInstall = null;

    private bool $forceUpdate = false;

    private string $workTempDir;

    private string $downloadedArchivePath;

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
        $this->currentlyInstalledVersion = $version;

        return $this;
    }

    public function getCurrentlyInstalledVersion(): ?string
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

    public function setDownloadedArchivePath(string $path): self
    {
        $this->downloadedArchivePath = $path;

        return $this;
    }

    public function getDownloadedArchivePath(): string
    {
        return $this->downloadedArchivePath;
    }
}
