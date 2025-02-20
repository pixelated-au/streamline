<?php

namespace Workbench\App\Console;

use Orchestra\Testbench\Foundation\Console\Kernel as ConsoleKernel;
use Override;
use Throwable;

/**
 * This exists only to bypass Orchestra's Kernel as that one is marked as final and cannot be mocked
 */
class Kernel extends ConsoleKernel
{
    /**
     * @throws \Throwable
     */
    #[Override]
    protected function reportException(Throwable $e): void
    {
        throw $e;
    }
}
