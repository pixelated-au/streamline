<?php

namespace Pixelated\Streamline\Factories;

use Illuminate\Support\Facades\Event;
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
        Event::dispatch(new CommandClassCallback('comment', 'Initializing Zip archive'));
        $result = $this->zip->open($this->zipArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException('Failed to create zip file: ' . $this->zipArchivePath);
        }
        Event::dispatch(new CommandClassCallback('comment', 'Zip archive initialized'));

        return $this;
    }

    public function makeArchive(string $source): static
    {
        Event::dispatch(new CommandClassCallback('comment', "Building Zip file from $source"));
        $iterator = app()->make(ArchiveBuilderIterator::class, ['path' => $source]);
        $basePathLength = strlen($source) + 1; // +1 for the trailing slash

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue; // Skip directories, they'll be created automatically
            }

            $filePath = $file->getPathname();
            $relativePath = substr($filePath, $basePathLength);
            if ($this->zip->addFile($filePath, $relativePath) === false) {
                throw new RuntimeException('Failed to add file to zip: ' . $filePath);
            }
        }
        if ($this->zip->close() === false) {
            throw new RuntimeException('Failed to close zip file');
        }

        Event::dispatch(new CommandClassCallback('success', 'Backup created successfully'));

        return $this;
    }
}
