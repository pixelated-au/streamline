<?php

namespace Pixelated\Streamline\Facades;

use Illuminate\Support\Facades\Facade;

class CleanUpAssets extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pixelated\Streamline\Services\CleanUpAssets::class;
    }
}
