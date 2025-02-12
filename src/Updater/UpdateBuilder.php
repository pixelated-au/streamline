<?php

namespace Pixelated\Streamline\Updater;

use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;

class UpdateBuilder implements UpdateBuilderInterface
{
    private ?string $requestedVersion = null;
    private ?string $versionToInstall = null;
    private bool $forceUpdate = false;
    private string $workTempDir;
    private string $downloadedArchivePath;

    public function __construct()
    {
        $this->workTempDir = config('streamline.work_temp_dir');
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
