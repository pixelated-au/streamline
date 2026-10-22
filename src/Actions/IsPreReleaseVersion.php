<?php

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Str;

class IsPreReleaseVersion
{
    public function execute(string $version): bool
    {
        return Str::match('/v?\d+(?:\.\d+)*-?[ab]$/', $version)
            || Str::endsWith($version, 'alpha')
            || Str::endsWith($version, 'beta');
    }
}
