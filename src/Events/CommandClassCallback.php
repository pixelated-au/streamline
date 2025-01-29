<?php

namespace Pixelated\Streamline\Events;

readonly class CommandClassCallback
{
    public function __construct(
        public string $action,
        public string $value
    ) {
    }
}
