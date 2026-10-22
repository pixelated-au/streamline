<?php

namespace Pixelated\Streamline\Testing\Mocks;

use FilesystemIterator;
use Phar;
use PharData;
use SplFileInfo;
use Traversable;

class PharDataFake extends PharData
{
    /** @noinspection MagicMethodsValidityInspection
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(
        public readonly string $filename,
        public readonly int $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
        public readonly ?string $alias = null,
        public readonly int $format = 0,
    ) {
        // do nothing
    }

    /**
     * @return array<string, string>
     */
    public function buildFromDirectory(string $directory, string $pattern = ''): array
    {
        return [];
    }

    /**
     * @param  Traversable<string, SplFileInfo|string>  $iterator
     * @return array<string, string>
     */
    public function buildFromIterator(Traversable $iterator, ?string $baseDirectory = null): array
    {
        return [];
    }

    public function compress(int $compression, ?string $extension = null): ?Phar
    {
        return null;
    }
}
