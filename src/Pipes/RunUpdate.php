<?php

namespace Pixelated\Streamline\Pipes;

use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use RuntimeException;

readonly class RunUpdate implements Pipe
{
    public function __construct(private InstantiateStreamlineUpdater $runUpdate) {}

    public function __invoke($builder): UpdateBuilderInterface
    {
        $this->runUpdate->execute($builder->getNextAvailableRepositoryVersion(), function (string $type, string $output) {
            if ($type === 'err') {
                CommandClassCallback::dispatch('error', $output);

                throw new RuntimeException($output);
            }

            CommandClassCallback::dispatch('info', $output);
        });

        return $builder;
    }
}
