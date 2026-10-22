<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pixelated\Streamline\Actions\GetAvailableVersions;
use Pixelated\Streamline\Actions\ProgressMeter;
use Pixelated\Streamline\Enums\CacheKeysEnum;

it('caches the versions and the tags GitHub flags as a pre-release', function() {
    Http::fake(['https://api.github.com/repos/*/releases*' => Http::response([
        ['tag_name' => 'v3.0.0', 'prerelease' => true],
        ['tag_name' => 'v2.9.0', 'prerelease' => false],
        ['tag_name' => 'v2.8.0'],
    ])]);

    $result = (new GetAvailableVersions(new ProgressMeter(null)))->execute();

    expect($result)->toBe('v3.0.0, v2.9.0, v2.8.0')
        ->and(Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value)->all())->toBe(['v3.0.0', 'v2.9.0', 'v2.8.0'])
        ->and(Cache::get(CacheKeysEnum::PRE_RELEASE_VERSIONS->value)->all())->toBe(['v3.0.0']);
});
