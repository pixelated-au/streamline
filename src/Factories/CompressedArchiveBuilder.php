<?php

namespace Pixelated\Streamline\Factories;

use Illuminate\Support\Facades\Event;
use Phar;
use PharData;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Iterators\ArchiveBuilderIterator;
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
        Event::dispatch(new CommandClassCallback('comment', "Instantiating Tar file with $this->archivingTool"));

        $this->pharData = app()->make($this->archivingTool, [
            'filename' => $this->tarArchivePath,
            'format'   => Phar::TAR,
        ]);
        Event::dispatch(new CommandClassCallback('comment', 'Tar file instantiated'));

        return $this;
    }

    public function makeArchive(string $source): static
    {
        Event::dispatch(new CommandClassCallback('comment', "Building Tar file from $source"));

        $iterator = app()->make(ArchiveBuilderIterator::class, ['path' => $source]);
        $this->pharData->buildFromIterator($iterator, $source);

        Event::dispatch(new CommandClassCallback('comment', 'Gzipping Tar file'));
        $this->pharData->compress(Phar::GZ);
        unset($this->pharData);
        return $this;
    }
}
