<?php

/** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Factories;

use Illuminate\Container\Attributes\Config;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Builds the configured external Process (streamline.external_process_class) for running a PHP script.
 *
 * This is kept as a separate, container-resolved class so that InstantiateStreamlineUpdater can have it
 * injected and tests can swap in a mock; it is not a leftover from pre-Laravel 11 support.
 */
class ProcessFactory
{
    public function __construct(
        #[Config('streamline.external_process_class')]
        private readonly string $processClass,
    ) {}

    public function invoke(string $script): Process
    {
        $php = (new PhpExecutableFinder)->find();

        if (!class_exists($this->processClass) || !is_a($this->processClass, Process::class, true)) {
            throw new RuntimeException(
                "Error: The configured external process class '$this->processClass' does not exist or is not a " .
                Process::class . '.'
            );
        }

        return new $this->processClass([$php, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-r', $script]);
    }
}
