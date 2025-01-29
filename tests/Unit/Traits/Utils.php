<?php

namespace Pixelated\Streamline\Tests\Unit\Traits;

trait Utils
{
    /** @var bool $hasOutput used to start the output buffer */
    protected bool $hasOutput = false;

    protected bool $hasDisabledErrorHandling = false;

    protected function startOutputBuffer(): void
    {
        $this->hasOutput = ob_start();
    }

    protected function disableErrorHandling(): void
    {
        $this->hasDisabledErrorHandling = true;
        error_reporting(E_ERROR | E_PARSE);
        set_error_handler(null);
    }

    protected function cleanUp(): void
    {
        if ($this->hasOutput) {
            ob_end_clean();
            $this->hasOutput = false;
        }
        if ($this->hasDisabledErrorHandling) {
            restore_error_handler();
            $this->hasDisabledErrorHandling = false;
        }
    }
}
