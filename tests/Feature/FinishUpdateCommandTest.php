<?php

use Illuminate\Support\Env;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\InstalledVersionSet;

it('sets the installed version and dispatches an event', function() {
    Event::fake();
    Cache::put(CacheKeysEnum::INSTALLED_VERSION->value, 'v0.0.0');
    $installedVersion = 'v100.0.2';
    Env::getRepository()?->set('STREAMLINE_APPLICATION_VERSION_INSTALLED', $installedVersion);

    $this->artisan('streamline:finish-update')
        ->expectsOutput("Persisting the new version number ($installedVersion) to the cache.")
        ->assertSuccessful();

    expect(Cache::get(CacheKeysEnum::INSTALLED_VERSION->value))->toBe($installedVersion);

    Event::assertDispatched(InstalledVersionSet::class, fn($event) => $event->version === $installedVersion);
});
