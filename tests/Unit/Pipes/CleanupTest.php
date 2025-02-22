<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\Cleanup;

it('should dispatch an Event with CommandClassCallback containing info and the directory path', function () {
    $mockBuilder = Mockery::mock(UpdateBuilderInterface::class);
    $tempDir = '/path/to/temp/dir';
    $mockBuilder->shouldReceive('getWorkTempDir')->once()->andReturn($tempDir);

    Event::fake();
    File::shouldReceive('deleteDirectory')->once()->with($tempDir);

    $cleanup = new Cleanup;
    $result = $cleanup($mockBuilder);

    Event::assertDispatched(fn (CommandClassCallback $event) => $event
        ->action === 'info' && $event->value === "Purging the temporary work directory: $tempDir"
    );

    expect($result)->toBe($mockBuilder);
});
