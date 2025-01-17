<?php

namespace Pixelated\Streamline\Facades;

use Illuminate\Support\Facades\Facade;

class Hasher extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pixelated\Streamline\Services\Hasher::class;
    }
}
