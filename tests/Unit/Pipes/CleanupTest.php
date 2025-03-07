<?php

use Pixelated\Streamline\Actions\Cleanup;
use Pixelated\Streamline\Events\CommandClassCallback;

it('should dispatch proper events', function() {
    $tempDir = '/path/to/temp/dir';

    Event::fake();
    File::shouldReceive('deleteDirectory')->once()->with($tempDir)->andReturnTrue();

    $cleanup = new Cleanup;
    $cleanup($tempDir);

    Event::assertDispatched(
        fn(CommandClassCallback $event) => $event
            ->action === 'comment' && $event->value === "Purging the temporary work directory: $tempDir"
    );
    Event::assertDispatched(
        fn(CommandClassCallback $event) => $event
            ->action === 'info' && $event->value === "Temporary work directory purged successfully: $tempDir"
    );
});

it('should dispatch an failed event when it could not delete the temp dir', function() {
    $tempDir = '/path/to/temp/dir';

    Event::fake();
    File::shouldReceive('deleteDirectory')->once()->with($tempDir)->andReturnFalse();

    $cleanup = new Cleanup;
    $cleanup($tempDir);

    Event::assertDispatched(
        fn(CommandClassCallback $event) => $event
            ->action === 'comment' && $event->value === "Purging the temporary work directory: $tempDir"
    );
    Event::assertDispatched(
        fn(CommandClassCallback $event) => $event
            ->action === 'error' && $event->value === "Failed to purge temporary work directory: $tempDir"
    );
});
