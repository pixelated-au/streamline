<?php

use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\RunUpdate;

it('should dispatch an info event when the update executes successfully', function() {
    $builder = $this->mock(UpdateBuilderInterface::class);
    $builder->expects('getRequestedVersion')->andReturnNull();
    $builder->expects('getNextAvailableRepositoryVersion')->andReturn('1.0.0');
    $builder->expects('getComposerPath')->andReturn('test/path');
    $builder->expects('getBackupArchivePath')->andReturn('/path/to/backup.zip');
    $builder->expects('markUpdateCompleted')->once()->andReturnSelf();

    $this->mock(InstantiateStreamlineUpdater::class)
        ->shouldReceive('execute')
        ->once()
        ->withArgs(['1.0.0', Mockery::type('string'), Mockery::type('Closure'), '/path/to/backup.zip'])
        ->andReturnUsing(function($version, $composerPath, $callback, $backupArchivePath) {
            $callback('out', 'Update successful');
        });

    Event::fake();

    $runUpdate = $this->app->make(RunUpdate::class);
    $runUpdate($builder);

    Event::assertDispatched(CommandClassCallback::class, function(CommandClassCallback $event) {
        return $event->action === 'line' && $event->value === 'Update successful';
    });
});

it('surfaces stderr output as a warning without throwing, so the updater is never interrupted', function() {
    $builder = $this->mock(UpdateBuilderInterface::class);
    $builder->expects('getRequestedVersion')->andReturnNull();
    $builder->expects('getNextAvailableRepositoryVersion')->andReturn('1.0.0');
    $builder->expects('getComposerPath')->andReturn('test/path');
    $builder->expects('getBackupArchivePath')->andReturn('/path/to/backup.zip');
    $builder->expects('markUpdateCompleted')->once()->andReturnSelf();

    $this->mock(InstantiateStreamlineUpdater::class)
        ->shouldReceive('execute')
        ->once()
        ->withArgs(['1.0.0', Mockery::type('string'), Mockery::type('Closure'), '/path/to/backup.zip'])
        ->andReturnUsing(function($version, $composerPath, $callback, $backupArchivePath) {
            $callback('err', 'Deprecated: something');
            $callback('out', 'Update continued');
        });

    Event::fake();

    $result = $this->app->make(RunUpdate::class)($builder);

    expect($result)->toBe($builder);
    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'warn' && $event->value === 'Deprecated: something'
    );
    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'line' && $event->value === 'Update continued'
    );
});

it('lets a failure raised by the updater process propagate', function() {
    $builder = $this->mock(UpdateBuilderInterface::class);
    $builder->expects('getRequestedVersion')->andReturnNull();
    $builder->expects('getNextAvailableRepositoryVersion')->andReturn('1.0.0');
    $builder->expects('getComposerPath')->andReturn('test/path');
    $builder->expects('getBackupArchivePath')->andReturn('/path/to/backup.zip');
    $builder->shouldNotReceive('markUpdateCompleted');

    $this->mock(InstantiateStreamlineUpdater::class)
        ->shouldReceive('execute')
        ->once()
        ->andReturnUsing(function($version, $composerPath, $callback) {
            $callback('out', 'Starting');

            throw new RuntimeException('The updater process exited with code 255');
        });

    Event::fake();

    $caught = null;

    try {
        $this->app->make(RunUpdate::class)($builder);
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toBe('The updater process exited with code 255');
    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'line' && $event->value === 'Starting'
    );
});
