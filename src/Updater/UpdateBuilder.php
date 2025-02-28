<?php

namespace Pixelated\Streamline\Updater;

use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Throwable;

class UpdateBuilder implements UpdateBuilderInterface
{
    private string $currentlyInstalledVersion;

    private ?string $requestedVersion = null;

    private ?string $versionToInstall = null;

    private bool $forceUpdate = false;

    private string $workTempDir;

    private string $downloadedArchivePath;

    private ?Throwable $error = null;

    public function __construct()
    {
        $this->workTempDir = config('streamline.work_temp_dir');
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

    public function setError(Throwable $error): self
    {
        $this->error = $error;

        return $this;
    }

    public function getError(): ?Throwable
    {
        return $this->error;
    }
}
