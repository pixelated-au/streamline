<?php

use Pixelated\Streamline\Actions\ProgressMeter;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Facades\GitHubApi;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\DownloadRelease;

it('should correctly set the downloaded archive path on the builder', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v1.0.0');
    $builder->shouldReceive('getWorkTempDir')->andReturn('/tmp/streamline');
    $builder->shouldReceive('setDownloadedArchivePath')->once()->with('/tmp/streamline/release.zip');

    Config::set('streamline.release_archive_file_name', 'release.zip');

    Event::shouldReceive('dispatch')->once();

    $this->app->bind(ProgressMeter::class, fn () => null);
    GitHubApi::shouldReceive('withWebUrl')->with('releases/download/v1.0.0/release.zip')->andReturnSelf();
    GitHubApi::shouldReceive('withDownloadPath')->with('/tmp/streamline/release.zip')->andReturnSelf();
    GitHubApi::shouldReceive('withProgressCallback')->andReturnSelf();
    GitHubApi::shouldReceive('get');

    $downloadRelease = new DownloadRelease();
    $result = $downloadRelease($builder);

    expect($result)->toBe($builder);
});

it('should dispatch an Event with the correct version information', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v1.2.3');
    $builder->shouldReceive('getWorkTempDir')->andReturn('/tmp/streamline');
    $builder->shouldReceive('setDownloadedArchivePath')->once();

    Config::set('streamline.release_archive_file_name', 'release.zip');

    Event::shouldReceive('dispatch')->once()->withArgs(function ($event) {
        return $event instanceof CommandClassCallback
            && $event->action === 'info'
            && $event->value === 'Downloading archive for version v1.2.3';
    });

    $this->app->bind(ProgressMeter::class, fn () => null);
    GitHubApi::shouldReceive('withWebUrl')->with('releases/download/v1.2.3/release.zip')->andReturnSelf();
    GitHubApi::shouldReceive('withDownloadPath')->with('/tmp/streamline/release.zip')->andReturnSelf();
    GitHubApi::shouldReceive('withProgressCallback')->andReturnSelf();
    GitHubApi::shouldReceive('get');

    $downloadRelease = new DownloadRelease();
    $result = $downloadRelease($builder);

    expect($result)->toBe($builder);
});
