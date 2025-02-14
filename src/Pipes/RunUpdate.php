<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\Event;
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
                Event::dispatch(new CommandClassCallback('error', $output));
                throw new RuntimeException($output);
            }

            Event::dispatch(new CommandClassCallback('info', $output));
        });

        return $builder;
    }
}
