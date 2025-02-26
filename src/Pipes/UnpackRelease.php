<?php

namespace Pixelated\Streamline\Pipes;

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use RuntimeException;
use ZipArchive;

readonly class UnpackRelease implements Pipe
{
    public function __construct(private ZipArchive $zip) {}

    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        CommandClassCallback::dispatch('info', 'Unpacking archive');

        $downloadedArchivePath = $builder->getDownloadedArchivePath();
        $tempDir               = $builder->getWorkTempDir();

        if ($this->zip->open($downloadedArchivePath) === true) {
            $this->zip->extractTo($tempDir);
            $this->zip->close();
        } else {
            throw new RuntimeException("Error: Failed to unpack $downloadedArchivePath");
        }

        return $builder;
    }
}
