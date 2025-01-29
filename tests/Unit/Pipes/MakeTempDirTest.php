<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\MakeTempDir;

it('should successfully create the temporary directory if it does not exist', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $tempDir = '/path/to/temp/dir';

    $builder->shouldReceive('getWorkTempDir')->once()->andReturn($tempDir);

    Event::shouldReceive('dispatch')->once()->with(Mockery::on(function ($event) use ($tempDir) {
        return $event instanceof CommandClassCallback &&
               $event->action === 'comment' &&
               $event->value === "Creating temporary directory $tempDir";
    }));

    File::shouldReceive('exists')->once()->with($tempDir)->andReturn(false);
    File::shouldReceive('makeDirectory')->once()->andReturn(true);

    $makeTempDir = new MakeTempDir();
    $result = $makeTempDir($builder);

    expect($result)->toBe($builder);
});

it('should throw a RuntimeException when the directory can not be created', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $tempDir = '/path/to/temp/dir';

    $builder->shouldReceive('getWorkTempDir')->once()->andReturn($tempDir);

    Event::shouldReceive('dispatch')->once()->with(Mockery::type(CommandClassCallback::class));

    File::shouldReceive('exists')->with($tempDir)->andReturnFalse();
    File::shouldReceive('makeDirectory')->andReturnFalse();
    File::shouldReceive('isDirectory')->with($tempDir)->andReturnFalse();

    $makeTempDir = new MakeTempDir();

    expect(fn() => $makeTempDir($builder))
        ->toThrow(RuntimeException::class, "Working directory '$tempDir' could not be created");
});

it('should dispatch an Event with the correct CommandClassCallback', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $tempDir = '/path/to/temp/dir';
    $builder->shouldReceive('getWorkTempDir')->once()->andReturn($tempDir);

    Event::fake();
    File::shouldReceive('exists')->once()->with($tempDir)->andReturn(true);

    $makeTempDir = new MakeTempDir();
    $result = $makeTempDir($builder);

    expect($result)->toBe($builder);
    Event::assertDispatched(CommandClassCallback::class, function (CommandClassCallback $event) use ($tempDir) {
        return $event->action === 'comment' &&
               $event->value === "Creating temporary directory $tempDir";
    });
});
