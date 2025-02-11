<?php
/** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Factories;

use Illuminate\Support\Facades\Config;
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
    )
    {
        //TODO restore this after upgrading to Laravel 11
        $this->processClass = Config::get('streamline.external_process_class');
    }

    public function invoke(string $script, string $cwd = null, array $env = null, int $timeout = 60, ?array $php = null): Process
    {
        return new $this->processClass($script, $cwd, $env, $timeout, $php);
    }
}
