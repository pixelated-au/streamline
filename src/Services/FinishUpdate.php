<?php

namespace Pixelated\Streamline\Services;

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

readonly class FinishUpdate
{
    public function __construct(
        protected UpdateBuilderInterface $builder,
        protected PhpExecutableFinder $phpExecutableFinder
    ) {}

    public function __invoke(): void
    {
        $process = resolve(Process::class, [
            'command' => [$this->phpExecutableFinder->find(), 'artisan', 'streamline:finish-update'],
            'cwd'     => $this->builder->getBasePath(),
        ]);
        $process->run();

        if ($process->isSuccessful()) {
            CommandClassCallback::dispatch('info', $process->getOutput());
        } else {
            CommandClassCallback::dispatch('error', $process->getErrorOutput());
        }
    }
}
