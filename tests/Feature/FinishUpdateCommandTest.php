<?php

use Mockery\MockInterface;
use Pixelated\Streamline\Actions\UncachedEnvironment;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\InstalledVersionSet;
use Pixelated\Streamline\Tests\Feature\Traits\CheckComposerPath;

pest()->uses(CheckComposerPath::class);

it('sets the installed version and dispatches an event', function() {
    Event::fake();
    $installedVersion = 'v100.0.2';

    $this->mock(
        UncachedEnvironment::class,
        fn(MockInterface $mock) => $mock
            ->shouldReceive('get')
            ->with('STREAMLINE_APPLICATION_VERSION_INSTALLED')
            ->andReturn($installedVersion)
    );

    $this->artisan('streamline:finish-update')
        ->expectsOutput("Persisting the new version number ($installedVersion) to the cache.")
        ->assertSuccessful();

    expect(Cache::get(CacheKeysEnum::INSTALLED_VERSION->value))->toBe($installedVersion);

    Event::assertDispatched(InstalledVersionSet::class, fn($event) => $event->version === $installedVersion);
});
