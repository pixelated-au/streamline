<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use RuntimeException;

class MakeTempDir implements Pipe
{
    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $tempDir = $builder->getWorkTempDir();

        Event::dispatch(new CommandClassCallback('comment', "Creating temporary directory $tempDir"));
        if (!File::exists($tempDir)
            && !File::makeDirectory(path: $tempDir, recursive: true)
            && !File::isDirectory($tempDir)) {
            throw new RuntimeException("Working directory '$tempDir' could not be created");
        }

        return $builder;
    }
}
