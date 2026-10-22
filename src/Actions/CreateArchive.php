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

    private readonly string $archivePath;

    public function __construct(
        private readonly string $sourceFolder,
        private readonly string $destinationPath,
        private readonly string $filename,
    ) {
        $this->archivePath = "$this->destinationPath/$this->filename";
        $this->archiver    = app()->make(
            abstract: CompressedArchiveBuilder::class,
            parameters: ['zipArchivePath' => $this->archivePath]
        );
    }

    /**
     * The full path of the archive this action writes. The filename is timestamped, so this is the
     * only way for later steps to know which file was actually created.
     */
    public function getArchivePath(): string
    {
        return $this->archivePath;
    }

    public function create(): void
    {
        CommandClassCallback::dispatch(
            'info',
            "Backing up the current installation to $this->archivePath."
        );

        // check that the source folder exists
        if (!File::exists($this->sourceFolder)) {
            throw new RuntimeException("Source folder '$this->sourceFolder' does not exist.");
        }

        $this->checkDestinationPath();

        $this->archiver->init()
            ->makeArchive($this->sourceFolder);

        // The archive is a complete copy of the installation, including the .env file, so it must
        // not be left readable by other local users.
        @chmod($this->archivePath, 0600);
    }

    protected function checkDestinationPath(): void
    {
        $directoryExists = File::isDirectory($this->destinationPath);

        if (!$directoryExists) {
            // Backups contain secrets, so the directory is not world-readable. It is re-checked
            // afterwards in case another process created it in the meantime.
            File::makeDirectory($this->destinationPath, 0700, true);
            $directoryExists = File::isDirectory($this->destinationPath);
        }

        if (!$directoryExists) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $this->destinationPath));
        }

        // check that the destination path is writable
        if (!File::isWritable($this->destinationPath)) {
            throw new RuntimeException("Destination path '$this->destinationPath' is not writable.");
        }

        // check that the filename is not already in use
        if (File::exists($this->archivePath)) {
            throw new RuntimeException("Archive file '$this->archivePath' already exists.");
        }
    }
}
