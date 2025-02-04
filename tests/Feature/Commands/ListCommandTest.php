<?php

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Tests\Feature\Traits\HttpMock;

pest()->use(HttpMock::class);

it('lists available updates', function () {
    $this->withPaginationHeader()
        ->mockHttpReleases();

    $this->artisan('streamline:list')
        ->expectsOutputToContain('Available versions: v2.8.7b, v2.8.6, ')
        ->assertExitCode(0);

    expect(Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value))
        ->toBeIterable()
        ->toHaveCount(60) // 60 items because we're calling the mock api twice
        ->toContain('v2.8.7b')
        ->toContain('v2.6.12');
});
