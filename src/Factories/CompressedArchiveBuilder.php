<?php

namespace Pixelated\Streamline\Factories;

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Iterators\ArchiveBuilderIterator;
use RuntimeException;
use ZipArchive;

readonly class CompressedArchiveBuilder
{
    public function __construct(
        private string $zipArchivePath,
        private ZipArchive $zip,
    ) {}

    public function init(): static
    {
        CommandClassCallback::dispatch('comment', 'Creating zip backup of existing release');
        $result = $this->zip->open($this->zipArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException('Failed to create zip file: ' . $this->zipArchivePath);
        }

        return $this;
    }

    public function makeArchive(string $source): static
    {
        CommandClassCallback::dispatch(
            'comment',
            "Building backup zip file from $source\n  ...This will take some time..."
        );
        $iterator       = resolve(ArchiveBuilderIterator::class, ['path' => $source]);
        $basePathLength = strlen($source) + 1; // +1 for the trailing slash

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue; // Skip directories, they'll be created automatically
            }

            $filePath     = $file->getPathname();
            $relativePath = substr($filePath, $basePathLength);

            if ($this->zip->addFile($filePath, $relativePath) === false) {
                throw new RuntimeException('Failed to add file to zip: ' . $filePath);
            }
        }

        if ($this->zip->close() === false) {
            throw new RuntimeException('Failed to close zip file');
        }

        CommandClassCallback::dispatch('info', 'Backup created successfully');

        return $this;
    }
}
