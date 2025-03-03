<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Factories\CompressedArchiveBuilder;
use Pixelated\Streamline\Iterators\ArchiveBuilderIterator;

it('throws a RuntimeException when zip creation fails', function () {
    $zipMock = Mockery::mock(ZipArchive::class);
    $zipMock->shouldReceive('open')
        ->once()
        ->andReturn(ZipArchive::ER_EXISTS); // Return an error code

    Event::fake([CommandClassCallback::class]);

    $builder = new CompressedArchiveBuilder('/path/to/archive.zip', $zipMock);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Failed to create zip file: /path/to/archive.zip');

    $builder->init();

    Event::assertDispatched(function (CommandClassCallback $event) {
        return $event->action === 'comment' && $event->value === 'Creating zip backup of existing release';
    });
});

it('creates a zip file at the expected path', function () {
    $zipPath = '/path/to/expected/archive.zip';
    $zipMock = Mockery::mock(ZipArchive::class);

    $zipMock->shouldReceive('open')
        ->once()
        ->with($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)
        ->andReturn(true);

    Event::fake([CommandClassCallback::class]);

    $builder = new CompressedArchiveBuilder($zipPath, $zipMock);
    $result  = $builder->init();

    expect($result)->toBeInstanceOf(CompressedArchiveBuilder::class);

    Event::assertDispatched(function (CommandClassCallback $event) {
        return $event->action === 'comment' && $event->value === 'Creating zip backup of existing release';
    });
});

it('throws a RuntimeException when adding a file to the zip fails', function () {
    $disk = Storage::fake();

    $zipPath = $disk->path('/path/to/archive.zip');
    $disk->makeDirectory('/path/to/source');
    $sourcePath = $disk->path('/path/to/source');

    $file = Mockery::mock(SplFileInfo::class);
    $file->shouldReceive('isDir')->andReturn(false);
    $file->shouldReceive('getPathname')->andReturn($disk->path('/path/to/source/test.txt'));

    $this->app->bind(ArchiveBuilderIterator::class, function () use ($file, $sourcePath) {
        $iterator = Mockery::mock(ArchiveBuilderIterator::class . '[!rewind,!beginIteration]', [$sourcePath]);
        $iterator->shouldIgnoreMissing();
        $iterator->shouldReceive('valid')->andReturn(true, false);
        $iterator->shouldReceive('current')->andReturn($file);
        $iterator->shouldReceive('key')->andReturn(0);
        $iterator->shouldReceive('next');

        return $iterator;
    });

    $zipMock = Mockery::mock(ZipArchive::class);
    $zipMock->shouldReceive('open')->andReturn(true);
    $zipMock->shouldReceive('close')->andReturn(true);
    $zipMock->shouldReceive('addFile')
        ->with($disk->path('/path/to/source/test.txt'), 'test.txt')
        ->andReturn(false);

    Event::fake([CommandClassCallback::class]);

    $builder = new CompressedArchiveBuilder($zipPath, $zipMock);
    $builder->init();

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Failed to add file to zip: ' . $disk->path('/path/to/source/test.txt'));

    $builder->makeArchive($sourcePath);

    Event::assertDispatched(fn (CommandClassCallback $event) => $event->action === 'comment'
        && $event->value                                                       === 'Building backup zip file from ' . $sourcePath
    );
});

it('throws a RuntimeException when closing the zip file fails', function () {
    $disk = Storage::fake();

    $zipPath = $disk->path('/path/to/archive.zip');
    $disk->makeDirectory('/path/to/source');
    $sourcePath = $disk->path('/path/to/source');

    $file = Mockery::mock(SplFileInfo::class);
    $file->shouldReceive('isDir')->andReturn(false);
    $file->shouldReceive('getPathname')->andReturn($disk->path('/path/to/source/test.txt'));

    $this->app->bind(ArchiveBuilderIterator::class, function () use ($file, $sourcePath) {
        $iterator = Mockery::mock(ArchiveBuilderIterator::class . '[!rewind,!beginIteration]', [$sourcePath]);
        $iterator->shouldIgnoreMissing();
        $iterator->shouldReceive('valid')->andReturn(true, false);
        $iterator->shouldReceive('current')->andReturn($file);
        $iterator->shouldReceive('key')->andReturn(0);
        $iterator->shouldReceive('next');

        return $iterator;
    });

    $zipMock = Mockery::mock(ZipArchive::class);
    $zipMock->shouldReceive('open')->andReturn(true);
    $zipMock->shouldReceive('addFile')->andReturn(true);
    $zipMock->shouldReceive('close')->andReturn(false); // This should trigger the RuntimeException

    Event::fake([CommandClassCallback::class]);

    $builder = new CompressedArchiveBuilder($zipPath, $zipMock);
    $builder->init();

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Failed to close zip file');

    $builder->makeArchive($sourcePath);

    Event::assertDispatched(fn (CommandClassCallback $event) => $event->action === 'comment'
        && $event->value                                                       === 'Building backup zip file from ' . $sourcePath
    );
});

it('should skip directories during iteration', function () {
    $disk = Storage::fake();

    $zipPath = $disk->path('/path/to/archive.zip');
    $disk->makeDirectory('/path/to/source');
    $sourcePath = $disk->path('/path/to/source');

    // Create a directory mock and a file mock
    $splFileInfo = Mockery::mock(SplFileInfo::class);
    $splFileInfo->shouldReceive('isDir')->andReturn(true, false);
    $splFileInfo->shouldReceive('getPathname')->andReturn($disk->path('/path/to/source/test.txt'));

    // Mock the iterator to return both a directory and a file
    $this->app->bind(ArchiveBuilderIterator::class, function () use ($splFileInfo, $sourcePath) {
        $iterator = Mockery::mock(ArchiveBuilderIterator::class . '[!rewind,!beginIteration]', [$sourcePath]);
        $iterator->shouldReceive('valid')->andReturn(true, true, false);
        $iterator->shouldReceive('current')->andReturn($splFileInfo);
        $iterator->shouldReceive('key')->andReturn(0, 1);
        $iterator->shouldReceive('next');

        return $iterator;
    });

    $zipMock = Mockery::mock(ZipArchive::class);
    $zipMock->shouldReceive('open')->andReturn(true);
    $zipMock->shouldReceive('close')->andReturn(true);

    // Assert that addFile is only called once (for the file, not for the directory)
    $zipMock->shouldReceive('addFile')
        ->once()
        ->with($disk->path('/path/to/source/test.txt'), 'test.txt')
        ->andReturn(true);

    Event::fake([CommandClassCallback::class]);

    $builder = new CompressedArchiveBuilder($zipPath, $zipMock);
    $builder->init();
    $result = $builder->makeArchive($sourcePath);

    expect($result)->toBeInstanceOf(CompressedArchiveBuilder::class);

    Event::assertDispatched(fn (CommandClassCallback $event) => $event->action === 'comment'
        && $event->value                                                       === 'Building backup zip file from ' . $sourcePath
    );

    Event::assertDispatched(fn (CommandClassCallback $event) => $event->action === 'success'
        && $event->value                                                       === 'Backup created successfully'
    );
});
