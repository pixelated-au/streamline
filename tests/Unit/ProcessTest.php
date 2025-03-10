<?php

use Pixelated\Streamline\Factories\ProcessFactory;
use Symfony\Component\Process\Process;

// TODO THIS TEST FILE IS ONLY NEEDED UNTIL THE UPDATE TO LARAVEL 11
it('should create a new static instance with default values when only script is provided', function() {
    $script = '';
    Config::set('streamline.external_process_class', Process::class);
    $process = new ProcessFactory;

    $result = $process->invoke($script);
    expect($result)->toBeInstanceOf(Process::class);
});

// TODO THIS TEST FILE IS ONLY NEEDED UNTIL THE UPDATE TO LARAVEL 11
it('should execute a php script', function() {
    $script = 'echo "Test Output...";';
    Config::set('streamline.external_process_class', Process::class);
    $process = new ProcessFactory;

    $result = $process->invoke($script)->run(
        fn(string $type, string $message) => expect($type)->toBe('out')
            ->and($message)->toBe('Test Output...')
    );
    expect($result)->toBe(0);
});
