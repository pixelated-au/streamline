<?php

namespace Pixelated\Streamline\Events;

use Symfony\Component\Console\Output\OutputInterface;

readonly class CommandClassCallback
{
    public function __construct(
        public string $action,
        public string $value,
        public int    $verbosity = OutputInterface::VERBOSITY_NORMAL,
    )
    {
    }
}
