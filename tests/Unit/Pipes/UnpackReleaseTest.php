<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\UnpackRelease;

it('should dispatch an Event with CommandClassCallback when invoked', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getDownloadedArchivePath')->andReturn('/path/to/archive.zip');
    $builder->shouldReceive('getWorkTempDir')->andReturn('/path/to/temp');

    $zip = Mockery::mock(ZipArchive::class);
    $zip->shouldReceive('open')->andReturn(true);
    $zip->shouldReceive('extractTo')->once();
    $zip->shouldReceive('close')->once();

    Event::fake();

    $unpackRelease = new UnpackRelease($zip);
    $result = $unpackRelease($builder);

    expect($result)->toBe($builder);
    Event::assertDispatched(CommandClassCallback::class, function (CommandClassCallback $event) {
        return $event->action === 'info' && $event->value === 'Unpacking archive';
    });
});

it('should throw a RuntimeException when zip->open fails', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getDownloadedArchivePath')->andReturn('/path/to/archive.zip');
    $builder->shouldReceive('getWorkTempDir')->andReturn('/path/to/temp');

    $zip = Mockery::mock(ZipArchive::class);
    $zip->shouldReceive('open')->andReturn(false);

    Event::fake();

    $unpackRelease = new UnpackRelease($zip);

    expect(fn() => $unpackRelease($builder))
        ->toThrow(RuntimeException::class, 'Error: Failed to unpack /path/to/archive.zip');

    Event::assertDispatched(CommandClassCallback::class, function (CommandClassCallback $event) {
        return $event->action === 'info' && $event->value === 'Unpacking archive';
    });
});
