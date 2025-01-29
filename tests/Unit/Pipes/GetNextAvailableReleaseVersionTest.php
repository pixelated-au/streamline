<?php

use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Actions\CheckAvailableVersions as DoCheck;
use Pixelated\Streamline\Pipes\GetNextAvailableReleaseVersion;

it('should set the version to install based on the result of checkAvailableVersions->execute()', function () {
    $mockBuilder = Mockery::mock(UpdateBuilderInterface::class);
    $mockBuilder->shouldReceive('isForceUpdate')->once()->andReturn(false);
    $mockBuilder->shouldReceive('setNextAvailableRepositoryVersion')->once()->with('2.0.0')->andReturnSelf();

    $mockCheckAvailableVersions = Mockery::mock(DoCheck::class);
    $mockCheckAvailableVersions->shouldReceive('execute')->once()->with(false)->andReturn('2.0.0');

    $checkAvailableVersions = new GetNextAvailableReleaseVersion($mockCheckAvailableVersions);

    $result = $checkAvailableVersions($mockBuilder);

    expect($result)->toBe($mockBuilder);
});

it('should set different version strings based on checkAvailableVersions result', function () {
    $versions = ['v1.0.0', 'v2.1.3-beta', 'latest'];

    $mockBuilder = Mockery::mock(UpdateBuilderInterface::class);
    $mockBuilder->shouldReceive('isForceUpdate')->andReturn(false);
    $mockBuilder->shouldReceive('setNextAvailableRepositoryVersion')->andReturnSelf();
    $mockBuilder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn(...$versions);

    $mockCheckAvailableVersions = Mockery::mock(DoCheck::class);
    $mockCheckAvailableVersions->shouldReceive('execute')->andReturn(...$versions);

    $checkAvailableVersions = new GetNextAvailableReleaseVersion($mockCheckAvailableVersions);

    foreach ($versions as $version) {
        $result = $checkAvailableVersions($mockBuilder);
        expect($result)->toBe($mockBuilder)
            ->and($mockBuilder->getNextAvailableRepositoryVersion())->toBe($version);
    }
});
