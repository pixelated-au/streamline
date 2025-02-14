<?php

use Pixelated\Streamline\Factories\ProcessFactory;
use Symfony\Component\Process\PhpProcess;

it('should create a new static instance with default values when only script is provided', function () {
    $script = '';
    Config::set('streamline.external_process_class', PhpProcess::class);
    $process = new ProcessFactory;

    $result = $process->invoke($script, timeout: 10);
    expect($result)->toBeInstanceOf(PhpProcess::class)
        ->and((int) $result->getTimeout())->toBe(10);
});

it('should execute a php script', function () {
    $script = '<?php echo "Test Output..."; ?>';
    Config::set('streamline.external_process_class', PhpProcess::class);
    $process = new ProcessFactory;

    $result = $process->invoke($script)->run(
        fn (string $type, string $message) => expect($type)->toBe('out')
            ->and($message)->toBe('Test Output...')
    );
    expect($result)->toBe(0);
});
