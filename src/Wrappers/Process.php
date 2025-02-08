<?php

namespace Pixelated\Streamline\Wrappers;

use Symfony\Component\Process\PhpProcess;

/**
 * This exists purely to inject a PhpProcess Object into the StreamlineUpdater class.
 */
class Process extends PhpProcess
{
    public function invoke(string $script, string $cwd = null, array $env = null, int $timeout = 60, ?array $php = null): static
    {
        return new static($script, $cwd, $env, $timeout, $php);
    }
}
