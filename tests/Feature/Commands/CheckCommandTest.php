<?php

use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use Pixelated\Streamline\Tests\Feature\Traits\CheckCommandCommon;
use Pixelated\Streamline\Tests\Feature\Traits\HttpMock;

pest()->use(CheckCommandCommon::class, HttpMock::class);

it('checks for available updates without a remote request', function () {
    $this->setDefaults(['availableVersions' => null])
        ->mockCache();

    $this
        ->artisan('streamline:check')
        ->expectsOutput('Next available version: v2.8.7b')
        ->assertExitCode(0);
});

it('checks for available updates with a remote request', function () {
    $this->setDefaults(['nextAvailableVersion' => null])
        ->mockCache();
    $this->mockHttpReleases();

    $this->instance(Artisan::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('call')
        ->once()
        ->with('streamline:list')
        ->andReturn(0)
    );

    $this->artisan('streamline:check')
        ->expectsOutputToContain('Next available version: v2.8.7b')
        ->assertExitCode(0);
});

it('checks for available updates forcing a remote request', function () {
    $this->mockCache();
    $this->mockHttpReleases();

    $this->instance(Artisan::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('call')
        ->once()
        ->with('streamline:list')
        ->andReturn(0)
    );

    $this->artisan('streamline:check --force')
        ->expectsOutputToContain('Next available version: v2.8.7b')
        ->assertExitCode(0);
});

it('throws an exception when doing a remote request because a version is missing', function () {
    $this->setDefaults([
        'nextAvailableVersion' => null,
        'availableVersions' => null,
    ])
        ->mockCache();
    $this->mockHttpReleases();

    $this->instance(Artisan::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('call')
        ->once()
        ->with('streamline:list')
        ->andReturn(0)
    );

    $this->artisan('streamline:check')
        ->expectsOutputToContain('The query to the GitHub repository appeared successful but no versions have been stored')
        ->assertExitCode(1);
});
