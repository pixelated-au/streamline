<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\InstalledVersionSet;

it('sets the installed version and dispatches an event', function () {
    Event::fake();

    Config::set('streamline.installed_version', 'v1.0.0');

    $this->artisan('streamline:init-installed-version')
        ->expectsOutput('Installed version has been configured.')
        ->assertSuccessful();

    expect(Cache::get(CacheKeysEnum::INSTALLED_VERSION->value))->toBe('v1.0.0');

    Event::assertDispatched(InstalledVersionSet::class, function ($event) {
        return $event->version === 'v1.0.0';
    });
});
