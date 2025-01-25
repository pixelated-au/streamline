<?php

namespace Pixelated\Streamline\Facades;

use Illuminate\Support\Facades\Facade;
use Pixelated\Streamline\Services;

/**
 * @see \Pixelated\Streamline\Services\ArchivedReleaseTools
 * @deprecated
 */
class ArchivedReleaseTools extends Facade
{
    /**
     * @codeCoverageIgnore
     */
    public static function fake(): Services\ArchivedReleaseTools
    {
        return tap(
            value: new Services\ArchivedReleaseTools(Zip::fake()),
            callback: static fn($fake) => self::swap($fake),
        );
    }

    protected static function getFacadeAccessor(): string
    {
        return Services\ArchivedReleaseTools::class;
    }
}
