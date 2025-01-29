<?php

namespace Pixelated\Streamline\Wrappers;

use Symfony\Component\Process\Process as BaseProcess;
use Symfony\Component\Process\PhpProcess;

/**
 * This class exists purely to inject a PhpProcess Object into the StreamlineUpdater class.
 */
class Process {
    public function invoke(string $script, string $cwd = null, array $env = null, int $timeout = 60, ?array $php = null): BaseProcess
    {
        return new PhpProcess($script, $cwd, $env, $timeout, $php);
    }
}
