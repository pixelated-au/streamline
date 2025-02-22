<?php

namespace Pixelated\Streamline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Symfony\Component\Console\Output\OutputInterface;

readonly class CommandClassCallback
{
    use Dispatchable;

    /**
     * @param  'comment'|'info'|'warn'|'error'|'call'  $action
     */
    public function __construct(
        public string $action,
        public string $value,
        public int $verbosity = OutputInterface::VERBOSITY_NORMAL,
    ) {}
}
