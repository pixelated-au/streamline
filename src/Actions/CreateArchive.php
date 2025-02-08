<?php

namespace Pixelated\Streamline\Actions;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Phar;
use PharData;
use RuntimeException;

class CreateArchive
{
    public function __construct(
        private readonly string $sourceFolder,
        private readonly string $destinationPath,
        private string          $filename,
    )
    {
        // check that the source folder exists
        if (!File::exists($this->sourceFolder)) {
            throw new RuntimeException("Source folder '$this->sourceFolder' does not exist.");
        }

        $this->filename = File::name($this->filename);
    }

    public function create(): void
    {
        $archivePath = $this->destinationPath . '/' . $this->filename . '.tar';
        $gzipPath    = $this->destinationPath . '/' . $this->filename . '.tgz';

        $this->checkDestinationPath($gzipPath);

        // Create a new Phar archive
        $phar = new PharData(
            filename: $archivePath,
            flags: FilesystemIterator::CURRENT_AS_FILEINFO
            | FilesystemIterator::SKIP_DOTS
            | FilesystemIterator::UNIX_PATHS,
            format: Phar::TAR,
        );

        // Build the archive from the directory
        $phar->buildFromDirectory($this->sourceFolder);

        // Compress the tar file to .tgz (tar.gz)
        $phar->compress(Phar::GZ);

        // Free the Phar object from memory
        unset($phar);

        // Rename the .tar.gz file to .tgz
        rename($archivePath . '.gz', $gzipPath);

        // Remove the uncompressed .tar file
        unlink($archivePath);
    }

    protected function checkDestinationPath(string $gzipPath): void
    {

        if (
            !File::isDirectory($this->destinationPath) &&
            !File::makeDirectory($this->destinationPath, 0755, true) &&
            !File::isDirectory($this->destinationPath)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $this->destinationPath));
        }

        // check that the destination path is writable
        if (!File::isWritable($this->destinationPath)) {
            throw new RuntimeException("Destination path '$this->destinationPath' is not writable.");
        }

        // check that the filename is not already in use
        if (File::exists($gzipPath)) {
            throw new RuntimeException("Archive file '$this->filename.tgz' already exists.");
        }
    }
}
