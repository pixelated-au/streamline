<?php

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Actions\CheckAvailableVersions;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Events\NextAvailableVersionUpdated;

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

    Artisan::shouldReceive('call')->with('streamline:list')->andReturn(0);
    Event::fake();

    $checkAvailableVersions = new CheckAvailableVersions;

    $result = $checkAvailableVersions->execute();
    expect($result)->toBe($versions[2]);
    Event::assertNotDispatched(CommandClassCallback::class);
    Event::assertDispatched(fn (NextAvailableVersionUpdated $event) => $event->version === $versions[2]);
});

it('should return the latest pre-release version when ignorePreReleases is true', function () {
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

    Artisan::shouldReceive('call')->with('streamline:list')->andReturn(0);
    Event::fake();

    $checkAvailableVersions = new CheckAvailableVersions;

    $result = $checkAvailableVersions->execute(ignorePreReleases: false);
    expect($result)->toBe($versions[2]);
    Event::assertNotDispatched(CommandClassCallback::class);
});

it('should refresh all available versions when there is an existing "next version" when force is used', function () {
    $versions = ['v2.1.6b', 'v2.1.5', 'v2.1.4', 'v1.0.0'];

    $cacheMock = Cache::partialMock();
    $cacheMock->shouldReceive('get')
        ->with(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value)
        ->andReturn($versions[3]);
    $cacheMock->shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn($versions);
    $cacheMock->shouldReceive('forever')
        ->andReturnTrue();

    Artisan::shouldReceive('call')->with('streamline:list')->andReturn(0);
    Event::fake();

    $checkAvailableVersions = new CheckAvailableVersions;

    $result = $checkAvailableVersions->execute(force: true);
    expect($result)->toBe($versions[1]);
    Event::assertDispatchedTimes(CommandClassCallback::class);
});
