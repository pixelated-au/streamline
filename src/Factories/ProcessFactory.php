<?php

/** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Factories;

use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * This exists purely to inject a PhpProcess Object into the StreamlineUpdater class.
 */
class ProcessFactory
{
    private readonly string $processClass;

    public function __construct(
        //        #[Config('streamline.external_process_class')]
        //        private readonly string $processClass,
    ) {
        // TODO restore this after upgrading to Laravel 11
        $this->processClass = Config::get('streamline.external_process_class');
    }

    public function invoke(string $script): Process
    {
        // TODO THIS CLASS IS ONLY NEEDED UNTIL THE UPDATE TO LARAVEL 11
        $php = (new PhpExecutableFinder)->find();

        return new $this->processClass([$php, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-r', $script]);
    }
}
