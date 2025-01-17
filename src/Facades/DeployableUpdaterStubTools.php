<?php

namespace Pixelated\Streamline\Facades;

use Illuminate\Support\Facades\Facade;

/** @see \Pixelated\Streamline\Services\DeployableUpdaterStubTools */
class DeployableUpdaterStubTools extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pixelated\Streamline\Services\DeployableUpdaterStubTools::class;
    }
}
