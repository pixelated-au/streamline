<?php

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Str;

class IsPreReleaseVersion
{
    public function execute($version): bool
    {
        return Str::match('/v?\d+(?:\.\d+)*-?[ab]$/', $version)
            || Str::endsWith($version, 'alpha')
            || Str::endsWith($version, 'beta');
    }
}
