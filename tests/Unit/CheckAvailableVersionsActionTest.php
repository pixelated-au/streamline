<?php

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Actions\CheckAvailableVersions;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\CommandClassCallback;

it('should return the latest non-prerelease version', function () {
    $versions = ['v2.1.6b', 'v2.1.5-beta', 'v2.1.4', 'v1.0.0'];

    $cacheMock = Cache::partialMock();
    $cacheMock->shouldReceive('get')
        ->with(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value)
        ->andReturnNull();
    $cacheMock->shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn($versions);
    $cacheMock->shouldReceive('forever')
        ->andReturnTrue();

    Event::fake();

    $checkAvailableVersions = new CheckAvailableVersions;

    $result = $checkAvailableVersions->execute();
    expect($result)->toBe($versions[2]);
    Event::assertDispatchedTimes(CommandClassCallback::class, 2);
});
