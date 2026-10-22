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
        // This always runs, including after an aborted update, so it cannot assume success. The
        // child process boots whatever code is now live; handing it the version this run set out to
        // install lets it compare against what is actually installed before claiming success.
        $expectedVersion = $this->builder->getRequestedVersion() ?? $this->builder->getNextAvailableRepositoryVersion();

        $process = resolve(Process::class, [
            'command' => [$this->phpExecutableFinder->find(), 'artisan', 'streamline:finish-update'],
            'cwd'     => $this->builder->getBasePath(),
            'env'     => [
                ...($expectedVersion ? ['STREAMLINE_EXPECTED_VERSION' => $expectedVersion] : []),
                // The version check alone cannot tell an abort from a success when the target is
                // already installed (a forced reinstall), so completion is reported explicitly.
                'STREAMLINE_UPDATE_COMPLETED' => $this->builder->isUpdateCompleted() ? '1' : '0',
            ],
        ]);
        $process->run();

        if ($process->isSuccessful()) {
            CommandClassCallback::dispatch('info', $process->getOutput());
        } else {
            CommandClassCallback::dispatch('error', $process->getErrorOutput());
        }
    }
}
