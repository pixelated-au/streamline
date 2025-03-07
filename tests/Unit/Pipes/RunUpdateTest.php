<?php

use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\RunUpdate;

it('should dispatch an info event when the update executes successfully', function() {
    $builder = $this->mock(UpdateBuilderInterface::class);
    $builder->expects('getRequestedVersion')->andReturnNull();
    $builder->expects('getNextAvailableRepositoryVersion')->andReturn('1.0.0');

    $this->mock(InstantiateStreamlineUpdater::class)
        ->shouldReceive('execute')
        ->once()
        ->withArgs(['1.0.0', Mockery::type('Closure')])
        ->andReturnUsing(function($version, $callback) {
            $callback('out', 'Update successful');
        });

    Event::fake();

    $runUpdate = $this->app->make(RunUpdate::class);
    $runUpdate($builder);

    Event::assertDispatched(CommandClassCallback::class, function(CommandClassCallback $event) {
        return $event->action === 'line' && $event->value === 'Update successful';
    });
});

it('should dispatch an error event when the update encounters an error', function() {
    $builder = $this->mock(UpdateBuilderInterface::class);
    $builder->expects('getRequestedVersion')->andReturnNull();
    $builder->expects('getNextAvailableRepositoryVersion')->andReturn('1.0.0');

    $this->mock(InstantiateStreamlineUpdater::class)
        ->shouldReceive('execute')
        ->once()
        ->withArgs(['1.0.0', Mockery::type('Closure')])
        ->andReturnUsing(function($version, $callback) {
            $callback('err', 'Update failed');
        });

    Event::fake();

    $runUpdate = $this->app->make(RunUpdate::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Update failed');

    $runUpdate($builder);

    Event::assertDispatched(CommandClassCallback::class, function(CommandClassCallback $event) {
        return $event->action === 'error' && $event->value === 'Update failed';
    });
});

it('should throw a RuntimeException with the error output when an error occurs', function() {
    $builder = $this->mock(UpdateBuilderInterface::class);
    $builder->expects('getRequestedVersion')->andReturnNull();
    $builder->expects('getNextAvailableRepositoryVersion')->andReturn('1.0.0');

    $this->mock(InstantiateStreamlineUpdater::class)
        ->shouldReceive('execute')
        ->once()
        ->withArgs(['1.0.0', Mockery::type('Closure')])
        ->andReturnUsing(function($version, $callback) {
            $callback('err', 'Critical error occurred');
        });

    Event::fake();

    $runUpdate = $this->app->make(RunUpdate::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Critical error occurred');

    $runUpdate($builder);

    Event::assertDispatched(CommandClassCallback::class, function(CommandClassCallback $event) {
        return $event->action === 'error' && $event->value === 'Critical error occurred';
    });
});
