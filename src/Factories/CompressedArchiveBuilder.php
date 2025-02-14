<?php

namespace Pixelated\Streamline\Factories;

use Illuminate\Support\Facades\Event;
use Phar;
use PharData;
use PharException;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Iterators\ArchiveBuilderIterator;
use Pixelated\Streamline\Testing\Mocks\PharDataFake;

class CompressedArchiveBuilder
{
    private string $archivingTool;

    private PharData $pharData;

    public function __construct(
        private readonly string $tarArchivePath,
    ) {
        $this->archivingTool = ! config('fake-production-environment') && app()->runningUnitTests()
            ? PharDataFake::class
            : PharData::class;
    }

    public function init(): static
    {
        Event::dispatch(new CommandClassCallback('comment', "Instantiating Tar builder with $this->archivingTool"));
        $this->pharData = app()->make($this->archivingTool, [
            'filename' => $this->tarArchivePath,
            'format' => Phar::TAR,
        ]);
        Event::dispatch(new CommandClassCallback('comment', 'Tar builder instantiated'));

        return $this;
    }

    /**
     * @throws \PharException
     */
    public function makeArchive(string $source): static
    {
        Event::dispatch(new CommandClassCallback('comment', "Building Tar file from $source"));

        $iterator = app()->make(ArchiveBuilderIterator::class, ['path' => $source]);
        $this->pharData->buildFromIterator($iterator, $source);

        Event::dispatch(new CommandClassCallback('comment', 'Gzip Tar file'));
        $this->pharData->compress(Phar::GZ);
        unset($this->pharData);
        Event::dispatch(new CommandClassCallback('comment', "Deleting non-compressed Tar file: $this->tarArchivePath"));
        try {
            PharData::unlinkArchive($this->tarArchivePath);
        } catch (PharException $e) {
            if ($this->archivingTool !== PharDataFake::class) {
                // @codeCoverageIgnoreStart
                throw $e;
                // @codeCoverageIgnoreEnd
            }
        }
        Event::dispatch(new CommandClassCallback('success', 'Backup created successfully'));

        return $this;
    }
}
