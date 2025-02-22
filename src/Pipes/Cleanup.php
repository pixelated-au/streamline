<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;

class Cleanup implements Pipe
{
    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $tempDir = $builder->getWorkTempDir();
        Event::dispatch(new CommandClassCallback('info', "Purging the temporary work directory: $tempDir"));

        File::deleteDirectory($tempDir);

        return $builder;
    }
}
