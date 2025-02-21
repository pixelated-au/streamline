<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Events\AvailableVersionsUpdated;
use Pixelated\Streamline\Tests\Feature\Traits\HttpMock;

pest()->use(HttpMock::class);

it('lists available updates', function () {
    $this->withPaginationHeader()
        ->mockHttpReleases();

    Event::fake();
    $this->artisan('streamline:list')
        ->expectsOutputToContain('Available versions: v2.8.7b, v2.8.6, ')
        ->assertExitCode(0);

    Event::assertDispatched(fn (AvailableVersionsUpdated $event) => $event->versions
        ->contains(fn ($value) => in_array($value, ['v2.8.7b', 'v2.8.6', 'v2.8.5'])));

    expect(Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value))
        ->toBeIterable()
        ->toHaveCount(60) // 60 items because we're calling the mock api twice
        ->toContain('v2.8.7b')
        ->toContain('v2.6.12');
});

it('can set the GitHub API token and it be seen in the configuration', function () {
    $this->withPaginationHeader()
        ->mockHttpReleases();

    $this->artisan('streamline:list --gh-token=test-token');
    expect(Config::get('streamline.github_auth_token'))->toBe('test-token');
});
