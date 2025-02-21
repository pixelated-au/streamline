<?php

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use Pixelated\Streamline\Tests\Feature\Traits\CheckCommandCommon;
use Pixelated\Streamline\Tests\Feature\Traits\HttpMock;

pest()->use(CheckCommandCommon::class, HttpMock::class);

it('checks for available updates without a remote request', function () {
    $this->setDefaults(['nextAvailableVersion' => null, 'availableVersions' => ['v2.8.7', 'v2.8.6', 'v2.8.5']]);
    $this->mockCache();

    Http::assertNothingSent();

    $this
        ->artisan('streamline:check')
        ->expectsOutput('Next available version: v2.8.7')
        ->assertExitCode(0);
});

it('checks for available updates with a remote request', function () {
    $this->setDefaults(['nextAvailableVersion' => null, 'availableVersions' => null]);

    Cache::shouldReceive('get')
        ->with(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value)
        ->andReturn($this->_nextAvailableVersion);

    Cache::shouldReceive('forever')->withAnyArgs();
    Cache::shouldReceive('get')
        ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
        ->andReturn(null, ['v2.8.7b', 'v2.8.6', 'v2.8.5']);

    $this->mockHttpReleases();

    $this->artisan('streamline:check')
        ->expectsOutputToContain('Next available version: v2.8.6')
        ->assertExitCode(0);
    Http::assertSentCount(1);
});

it('checks for available updates forcing a remote request', function () {
    $this->setDefaults(['nextAvailableVersion' => '2.8.6', 'availableVersions' => ['v2.8.7b', 'v2.8.6', 'v2.8.5']])
        ->mockCache();
    $this->mockHttpReleases();

    $this->artisan('streamline:check --force')
        ->expectsOutputToContain('Next available version: v2.8.6')
        ->assertExitCode(0);
});

it('throws an exception when doing a remote request because a version is missing', function () {
    $this->setDefaults(['nextAvailableVersion' => null, 'availableVersions' => null])
        ->mockCache();
    $this->mockHttpReleases();

    $this->artisan('streamline:check')
        ->expectsOutputToContain('The next available version could not be determined')
        ->assertExitCode(1);
});
