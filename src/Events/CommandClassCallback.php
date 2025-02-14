<?php

namespace Pixelated\Streamline\Events;

use Symfony\Component\Console\Output\OutputInterface;

readonly class CommandClassCallback
{
    /**
     * @param  'comment'|'info'|'warn'|'error'|'call'  $action
     */
    public function __construct(
        public string $action,
        public string $value,
        public int $verbosity = OutputInterface::VERBOSITY_NORMAL,
    ) {}
}
