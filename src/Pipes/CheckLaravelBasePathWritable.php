<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\File;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use RuntimeException;

class CheckLaravelBasePathWritable implements Pipe
{
    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $basePath = base_path();

        if (!File::isWritable($basePath)) {
            throw new RuntimeException(
                message: "Error: The Laravel base path ($basePath) is not writable. " .
                'Please ensure you have the necessary permissions to write to this directory ' .
                'because running updates requires write access to the Laravel installation.'
            );
        }

        return $builder;
    }
}
