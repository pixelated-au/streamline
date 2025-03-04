<?php

namespace Pixelated\Streamline\Events;

use Illuminate\Foundation\Events\Dispatchable;

readonly class NextAvailableVersionUpdated
{
    use Dispatchable;

    public function __construct(public string $version) {}
}
