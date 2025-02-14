<?php

namespace Pixelated\Streamline\Pipes;

use Pixelated\Streamline\Actions\CheckAvailableVersions as DoCheck;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;

readonly class GetNextAvailableReleaseVersion implements Pipe
{
    public function __construct(private DoCheck $checkAvailableVersions) {}

    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $builder->setNextAvailableRepositoryVersion(
            $this->checkAvailableVersions->execute($builder->isForceUpdate())
        );

        return $builder;
    }
}
