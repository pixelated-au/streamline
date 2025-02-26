<?php

/** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Facades\File;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Factories\CompressedArchiveBuilder;
use RuntimeException;

class CreateArchive
{
    private readonly CompressedArchiveBuilder $archiver;

    private readonly string $gzipPath;

    public function __construct(
        private readonly string $sourceFolder,
        private readonly string $destinationPath,
        private readonly string $filename,
    ) {
        $this->gzipPath = "$this->destinationPath/$this->filename";
        $tarPath        = File::dirname($this->gzipPath) . '/' . File::name($this->gzipPath) . '.zip';
        $this->archiver = app()->make(
            abstract: CompressedArchiveBuilder::class,
            parameters: ['zipArchivePath' => $tarPath]
        );
    }

    public function create(): void
    {
        CommandClassCallback::dispatch('info', "Backing up the current installation to $this->gzipPath");

        // check that the source folder exists
        if (!File::exists($this->sourceFolder)) {
            throw new RuntimeException("Source folder '$this->sourceFolder' does not exist.");
        }

        $this->checkDestinationPath();

        $this->archiver->init()
            ->makeArchive($this->sourceFolder);
    }

    protected function checkDestinationPath(): void
    {
        if (
            !File::isDirectory($this->destinationPath) && !File::makeDirectory($this->destinationPath, 0755, true) && !File::isDirectory($this->destinationPath)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $this->destinationPath));
        }

        // check that the destination path is writable
        if (!File::isWritable($this->destinationPath)) {
            throw new RuntimeException("Destination path '$this->destinationPath' is not writable.");
        }

        // check that the filename is not already in use
        if (File::exists($this->gzipPath)) {
            throw new RuntimeException("Archive file '$this->gzipPath' already exists.");
        }
    }
}
