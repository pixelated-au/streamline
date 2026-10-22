<?php

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Str;

class IsPreReleaseVersion
{
    /**
     * A version is a pre-release when it carries a SemVer pre-release suffix of any kind (anything after a
     * "-", e.g. -rc1, -RC.1, -alpha.1, -beta.2, -dev, -preview) or a legacy trailing a/b/alpha/beta marker.
     */
    public function execute(string $version): bool
    {
        // Build metadata ("+...") may legitimately contain hyphens and is not part of the pre-release identifier
        $version = Str::before($version, '+');

        return Str::contains($version, '-')
            || Str::match('/v?\d+(?:\.\d+)*-?[ab]$/', $version) !== ''
            || Str::endsWith($version, 'alpha')
            || Str::endsWith($version, 'beta');
    }
}
