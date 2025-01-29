<?php

namespace Pixelated\Streamline\Testing\Mocks;

use ZipArchive;

class ZipArchiveFake extends ZipArchive
{

    public function open(string $filename, int $flags = null): bool|int
    {
        return true;
    }

    public function extractTo(string $pathto, array|string|null $files = null): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }
}
