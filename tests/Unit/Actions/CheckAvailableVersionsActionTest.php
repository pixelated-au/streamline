<?php

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Actions\CheckAvailableVersions;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Events\NextAvailableVersionUpdated;

it('should return the latest version', function() {
    $versions = ['v2.1.6', 'v2.1.5', 'v2.1.4', 'v1.0.0'];

    $cacheMock = Cache::partialMock();
    $cacheMock->shouldReceive('get')->with(CacheKeysEnum::PRE_RELEASE_VERSIONS->value)->andReturn([]);
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

    $checkAvailableVersions = resolve(CheckAvailableVersions::class);

    $result = $checkAvailableVersions->execute();
    expect($result)->toBe($versions[0]);
    Event::assertNotDispatched(CommandClassCallback::class);
    Event::assertDispatched(fn(NextAvailableVersionUpdated $event) => $event->version === $versions[0]);
});

it('should return the latest non-prerelease version', function() {
    $versions = ['v2.1.6b', 'v2.1.5-beta', 'v2.1.4', 'v1.0.0'];

    $cacheMock = Cache::partialMock();
    $cacheMock->shouldReceive('get')->with(CacheKeysEnum::PRE_RELEASE_VERSIONS->value)->andReturn([]);
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

    $checkAvailableVersions = resolve(CheckAvailableVersions::class);

    $result = $checkAvailableVersions->execute();
    expect($result)->toBe($versions[2]);
    Event::assertNotDispatched(CommandClassCallback::class);
    Event::assertDispatched(fn(NextAvailableVersionUpdated $event) => $event->version === $versions[2]);
});

it('should return the latest pre-release version when ignorePreReleases is false', function() {
    $versions = ['v2.1.6b', 'v2.1.5-beta', 'v2.1.4a', 'v2.1.3-alpha', 'v2.1.2', 'v1.0.0'];

    $cacheMock = Cache::partialMock();
    $cacheMock->shouldReceive('get')->with(CacheKeysEnum::PRE_RELEASE_VERSIONS->value)->andReturn([]);
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

    $checkAvailableVersions = resolve(CheckAvailableVersions::class);

    $result = $checkAvailableVersions->execute(ignorePreReleases: false);
    expect($result)->toBe($versions[0]);
    Event::assertNotDispatched(CommandClassCallback::class);
});

it('should refresh all available versions when there is an existing "next version" when force is used', function() {
    $versions = ['v2.1.6b', 'v2.1.5', 'v2.1.4', 'v1.0.0'];

    $cacheMock = Cache::partialMock();
    $cacheMock->shouldReceive('get')->with(CacheKeysEnum::PRE_RELEASE_VERSIONS->value)->andReturn([]);
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

    $checkAvailableVersions = resolve(CheckAvailableVersions::class);

    $result = $checkAvailableVersions->execute(force: true);
    expect($result)->toBe($versions[1]);
    Event::assertDispatchedTimes(CommandClassCallback::class);
});

it('throws an exception when availableVersions are not in the cache', function() {
    $cacheMock = Cache::partialMock();
    $cacheMock->shouldReceive('get')->with(CacheKeysEnum::PRE_RELEASE_VERSIONS->value)->andReturn([]);
    $cacheMock->shouldReceive('get')
        ->with(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value)
        ->andReturnNull();
    $cacheMock->shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn([]);

    Artisan::shouldReceive('call')->with('streamline:list')->andReturn(1);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('The next available version could not be determined.');

    $checkAvailableVersions = resolve(CheckAvailableVersions::class);

    $result = $checkAvailableVersions->execute(force: true);
    expect($result)->toBeNull();
});

function mockVersionCache(array $versions, array $flagged = []): void
{
    $cacheMock = Cache::partialMock();
    $cacheMock->shouldReceive('get')->with(CacheKeysEnum::PRE_RELEASE_VERSIONS->value)->andReturn($flagged);
    $cacheMock->shouldReceive('get')->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)->andReturn($versions);
    $cacheMock->shouldReceive('forever')->andReturnTrue();
    Artisan::shouldReceive('call')->with('streamline:list')->andReturn(0);
    Event::fake();
}

it('skips versions with any SemVer pre-release suffix', function() {
    mockVersionCache(['v3.0.0-rc1', 'v2.9.0-RC.1', 'v2.8.0-dev', 'v2.7.0-preview', 'v2.6.0']);

    expect(resolve(CheckAvailableVersions::class)->execute())->toBe('v2.6.0');
});

it('skips versions GitHub flags as a pre-release even when the tag looks stable', function() {
    mockVersionCache(['v3.0.0', 'v2.9.0', 'v2.8.0'], ['v3.0.0']);

    expect(resolve(CheckAvailableVersions::class)->execute())->toBe('v2.9.0')
        ->and(resolve(CheckAvailableVersions::class)->execute(ignorePreReleases: false))->toBe('v3.0.0');
});

it('fails with a helpful message when every available version is a pre-release', function() {
    mockVersionCache(['v3.0.0-rc1', 'v2.9.0-beta.1'], []);

    expect(fn() => resolve(CheckAvailableVersions::class)->execute())
        ->toThrow(RuntimeException::class, 'Every available version is a pre-release; use --pre-releases to include them.');
});
