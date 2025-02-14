<?php

use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\VerifyVersion;

it('should throw RuntimeException when requested version does not exist', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturn('v1.0.0');
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v2.0.0');
    $builder->shouldReceive('isForceUpdate')->andReturn(false);

    Event::shouldReceive('dispatch')->once()->with(Mockery::type(CommandClassCallback::class));

    Cache::shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn(collect(['v2.0.0']));

    $verifyVersion = new VerifyVersion;

    expect(fn () => $verifyVersion->__invoke($builder))
        ->toThrow(RuntimeException::class, 'Version v1.0.0 is not a valid version!');
});

it('should throw RuntimeException when requested version is not greater than current version and force update is false', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturn('v1.0.0');
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v2.0.0');
    $builder->shouldReceive('isForceUpdate')->andReturn(false);

    Event::shouldReceive('dispatch')->once()->with(Mockery::type(CommandClassCallback::class));

    Cache::shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn(collect(['v1.0.0', 'v2.0.0']));

    $verifyVersion = new VerifyVersion;

    expect(fn () => $verifyVersion->__invoke($builder))
        ->toThrow(RuntimeException::class, 'Version v1.0.0 is not greater than the current version (v2.0.0)');
});

it('should warn but not throw exception when requested version is not greater than current version and force update is true', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturn('v1.0.0');
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v2.0.0');
    $builder->shouldReceive('isForceUpdate')->andReturnTrue();

    Event::fake();
    Cache::shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn(collect(['v1.0.0', 'v2.0.0']));

    $verifyVersion = new VerifyVersion;

    expect(fn () => $verifyVersion->__invoke($builder))->not->toThrow(RuntimeException::class);

    Event::assertDispatchedTimes(CommandClassCallback::class, 2);

    Event::assertDispatched(CommandClassCallback::class, function (CommandClassCallback $event) {
        if ($event->action === 'info') {
            return $event->value === 'hanging deployment to version: v1.0.0';
        }
        if ($event->action === 'warn') {
            return $event->value === 'Version v1.0.0 is not greater than the current version (v2.0.0) (Forced update)';
        }

        return false;
    });
});

it('should return null when next version is the same as the installed version', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturnNull();
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v2.0.0');
    $builder->shouldReceive('isForceUpdate')->andReturn(false);

    Event::fake();

    Cache::shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn(collect(['v1.0.0', 'v2.0.0']));

    Config::set('streamline.installed_version', 'v3.0.0');

    $verifyVersion = new VerifyVersion;
    expect($verifyVersion->__invoke($builder))->toBeNull();

    Event::assertDispatchedTimes(CommandClassCallback::class);
    Event::assertDispatched(CommandClassCallback::class, function (CommandClassCallback $event) {
        return $event->action === 'warn' && $event->value === 'You are currently using the latest version (v2.0.0)';
    });
});

it('should throw RuntimeException when next version does not exist and no specific version is requested', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturnNull();
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v2.0.0');
    $builder->shouldReceive('isForceUpdate')->andReturn(false);

    Config::set('streamline.installed_version', 'v1.0.0');

    Event::fake();

    Cache::shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn(collect(['v1.0.0']));

    $verifyVersion = new VerifyVersion;

    expect(fn () => $verifyVersion->__invoke($builder))
        ->toThrow(RuntimeException::class, 'Unexpected! The next available version: v2.0.0 cannot be found.');

    Event::assertDispatched(CommandClassCallback::class, function (CommandClassCallback $event) {
        return $event->action === 'info' && $event->value === 'Deploying to next available version: v2.0.0';
    });
});

it('should use the next available version when no specific version is requested', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturnNull();
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v2.0.0');
    $builder->shouldReceive('isForceUpdate')->andReturn(false);

    Config::set('streamline.installed_version', 'v1.0.0');

    Event::fake();

    Cache::shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn(collect(['v1.0.0', 'v2.0.0']));

    $verifyVersion = new VerifyVersion;

    expect($verifyVersion->__invoke($builder))->toBe($builder);

    Event::assertDispatched(CommandClassCallback::class, function (CommandClassCallback $event) {
        return $event->action === 'info' && $event->value === 'Deploying to next available version: v2.0.0';
    });
});

it('should dispatch appropriate info and warning events based on version comparisons', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturn('v1.0.0');
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v1.5.0');
    $builder->shouldReceive('isForceUpdate')->andReturnTrue();

    Config::set('streamline.installed_version', 'v1.5.0');

    Event::fake();

    Cache::shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn(collect(['v1.0.0', 'v1.5.0']));

    $verifyVersion = new VerifyVersion;

    expect($verifyVersion->__invoke($builder))->toBe($builder);

    Event::assertDispatchedTimes(CommandClassCallback::class, 2);
    Event::assertDispatched(CommandClassCallback::class, function (CommandClassCallback $event) {
        if ($event->action === 'info') {
            return $event->value === 'Changing deployment to version: v1.0.0';
        }
        if ($event->action === 'warn') {
            return $event->value === 'Version v12.0.0 is not greater than the current version (v1.5.0) (Forced update)';
        }

        return false;
    });
});
