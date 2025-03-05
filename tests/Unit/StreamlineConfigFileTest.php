<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Updater\UpdateBuilder;

it('outputs an info message when the cleanup process fails', function() {
    Process::preventStrayProcesses();

    //    Process::fake([
    //        Process::result(output: 'Test success message'),
    //    ]);

    $this->app->bind(
        \Symfony\Component\Process\Process::class,
        function() {
            // For some reason, $this->mock(...) isn't working as expected, so I'm mocking it manually
            $mock = Mockery::mock(\Symfony\Component\Process\Process::class);
            $mock->shouldReceive('run')
                ->andReturn(0);
            $mock->shouldReceive('isSuccessful')
                ->andReturnTrue();
            $mock->shouldReceive('getOutput')
                ->andReturn('Test success message');

            return $mock;
        }
    );

    Event::fake(CommandClassCallback::class);

    $callback = Config::get('streamline.pipeline-finish');
    $callback(new UpdateBuilder);

    Event::assertDispatchedTimes(CommandClassCallback::class);

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $callback) => $callback->action === 'info' && Str::startsWith($callback->value, 'Test success message')
    );
});

it('outputs an error when the cleanup process fails', function() {
    Process::preventStrayProcesses();

    //    Process::fake([
    //        Process::result(errorOutput: 'Test error message', exitCode: 1),
    //    ]);

    $this->app->bind(
        \Symfony\Component\Process\Process::class,
        function() {
            // For some reason, $this->mock(...) isn't working as expected, so I'm mocking it manually
            $mock = Mockery::mock(\Symfony\Component\Process\Process::class);
            $mock->shouldReceive('run')
                ->andReturn(1);
            $mock->shouldReceive('isSuccessful')
                ->andReturnFalse();
            $mock->shouldReceive('getErrorOutput')
                ->andReturn('Test error message');

            return $mock;
        }
    );
    Event::fake(CommandClassCallback::class);

    $callback = Config::get('streamline.pipeline-finish');
    $callback(new UpdateBuilder);

    Event::assertDispatchedTimes(CommandClassCallback::class);

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $callback) => $callback->action === 'error' && Str::startsWith($callback->value, 'Test error message')
    );
});
