<?php

namespace Pixelated\Streamline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

readonly class AvailableVersionsUpdated
{
    use Dispatchable;

    /**
     * @param  Collection<int, string>  $versions
     */
    public function __construct(public Collection $versions) {}
}
