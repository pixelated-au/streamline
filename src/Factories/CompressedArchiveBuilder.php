<?php

namespace Pixelated\Streamline\Factories;

use FilesystemIterator;
use Phar;
use PharData;
use Pixelated\Streamline\Testing\Mocks\PharDataFake;

class CompressedArchiveBuilder
{
    private string $archivingTool;
    private PharData $pharData;

    public function __construct(
        private readonly string $tarArchivePath,
    )
    {
        $this->archivingTool = !config('fake-production-environment') && app()->runningUnitTests()
            ? PharDataFake::class
            : PharData::class;
    }

    public function init(): static
    {
        $this->pharData = app()->make($this->archivingTool, [
            'filename' => $this->tarArchivePath,
            'flags'    => FilesystemIterator::CURRENT_AS_FILEINFO
                | FilesystemIterator::SKIP_DOTS
                | FilesystemIterator::UNIX_PATHS,
            'format'   => Phar::TAR,
        ]);
        return $this;
    }

    public function makeArchive(string $source): static
    {
        $this->pharData->buildFromDirectory($source);
        $this->pharData->compress(Phar::GZ);
        unset($this->pharData);
        return $this;
    }
}
