<?php

namespace Pixelated\Streamline\Testing\Mocks;

use FilesystemIterator;
use Phar;
use PharData;

class PharDataFake extends PharData {
    /** @noinspection MagicMethodsValidityInspection
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(string $filename, int $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS, ?string $alias = null, int $format = 0)
    {
        // do nothing
    }

    public function buildFromDirectory(string $directory, string $pattern = ''): array
    {
        return [];
    }

    public function compress(int $compression, ?string $extension = null): ?Phar
    {
        return null;
    }


}
