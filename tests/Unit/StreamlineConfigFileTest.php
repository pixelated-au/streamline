<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Updater\UpdateBuilder;

it('outputs an info message when the cleanup process fails', function () {
    Process::preventStrayProcesses();

    Process::fake([
        Process::result(output: 'Test success message'),
    ]);
    Event::fake(CommandClassCallback::class);

    $callback = Config::get('streamline.pipeline-finish');
    $callback(new UpdateBuilder);

    Event::assertDispatchedTimes(CommandClassCallback::class);

    Event::assertDispatched(
        CommandClassCallback::class,
        fn (CommandClassCallback $callback) => $callback->action === 'info' &&
            Str::startsWith($callback->value, 'Test success message')
    );
});

it('outputs an error when the cleanup process fails', function () {
    Process::preventStrayProcesses();

    Process::fake([
        Process::result(errorOutput: 'Test error message', exitCode: 1),
    ]);
    Event::fake(CommandClassCallback::class);

    $callback = Config::get('streamline.pipeline-finish');
    $callback(new UpdateBuilder);

    Event::assertDispatchedTimes(CommandClassCallback::class);

    Event::assertDispatched(
        CommandClassCallback::class,
        fn (CommandClassCallback $callback) => $callback->action === 'error' &&
            Str::startsWith($callback->value, 'Test error message')
    );
});
