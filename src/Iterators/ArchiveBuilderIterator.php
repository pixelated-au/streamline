<?php

namespace Pixelated\Streamline\Iterators;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveFilterIterator;
use RecursiveIteratorIterator;

class ArchiveBuilderIterator extends RecursiveIteratorIterator
{
    public function __construct(string $path)
    {
        $iterator = new RecursiveDirectoryIterator(
            directory: $path,
            flags: FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
        );

        $filteredIterator = new class($iterator) extends RecursiveFilterIterator
        {
            public function accept(): bool
            {
                $current = $this->current();

                return !$current->isLink();
            }
        };

        parent::__construct($filteredIterator);
        $this->rewind();
    }
}
