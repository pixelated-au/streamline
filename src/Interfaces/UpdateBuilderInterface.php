<?php

namespace Pixelated\Streamline\Interfaces;

interface UpdateBuilderInterface
{
    public function setBasePath(string $basePath): UpdateBuilderInterface;

    public function getBasePath(): string;

    public function setComposerPath(string $path): UpdateBuilderInterface;

    public function getComposerPath(): string;

    public function setCurrentlyInstalledVersion(string $version): UpdateBuilderInterface;

    public function getCurrentlyInstalledVersion(): ?string;

    public function setRequestedVersion(string $version): UpdateBuilderInterface;

    public function getRequestedVersion(): ?string;

    public function setNextAvailableRepositoryVersion(string $version): UpdateBuilderInterface;

    public function getNextAvailableRepositoryVersion(): ?string;

    public function forceUpdate(bool $doForce): UpdateBuilderInterface;

    public function isForceUpdate(): bool;

    public function getWorkTempDir(): string;

    public function setDownloadedArchivePath(string $path): self;

    public function getDownloadedArchivePath(): string;
}
