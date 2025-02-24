<?php

use Illuminate\Support\Facades\File;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\CheckLaravelBasePathWritable;

it('should throw RuntimeException when base_path is not writable', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);

    $basePath = '/path/to/laravel';
    $this->app->setBasePath($basePath);
    File::expects('isWritable')->with($basePath)->andReturn(false);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Error: The Laravel base path ($basePath) is not writable.");

    (new CheckLaravelBasePathWritable)($builder);
});

it('should return the original UpdateBuilderInterface instance when base_path is writable', function () {
    $builder = Mockery::mock(UpdateBuilderInterface::class);

    $basePath = '/path/to/laravel';
    $this->app->setBasePath($basePath);
    File::shouldReceive('isWritable')->with($basePath)->andReturn(true);

    $result = (new CheckLaravelBasePathWritable)($builder);

    expect($result)->toBe($builder);
});
