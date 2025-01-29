<?php

namespace Pixelated\Streamline\Pipeline;

use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;

interface Pipe
{
    public function __invoke(UpdateBuilderInterface $builder): ?UpdateBuilderInterface;
}
