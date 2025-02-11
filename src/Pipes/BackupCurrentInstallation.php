<?php

namespace Pixelated\Streamline\Pipes;

use Pixelated\Streamline\Actions\CreateArchive;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;

readonly class BackupCurrentInstallation implements Pipe
{
    public function __construct(private CreateArchive $createArchive)
    {
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $this->createArchive->create();
        return $builder;
    }
}
