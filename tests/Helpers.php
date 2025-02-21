<?php

use Illuminate\Console\OutputStyle;
use Pixelated\Streamline\Tests\LaravelTestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\StreamOutput;

function fakeOutputStyle(): void
{
    /** @returns LaravelTestCase */
    test()->instance(
        OutputStyle::class,
        new OutputStyle(
            new StringInput(''),
            new StreamOutput(fopen('php://memory', 'a+b'))
        )
    );
}

function workbench_path(): string
{
    return getcwd() . '/workbench';
}

function laravel_path(string $path = ''): string
{
    /** @noinspection PhpPossiblePolymorphicInvocationInspection */
    return rtrim(test()->deploymentPath() . "/$path", '/');
}

function working_path(string $path = ''): string
{
    if (!file_exists(laravel_path('mock_deployment'))) {
        /** @noinspection NestedPositiveIfStatementsInspection */
        if (!mkdir($concurrentDirectory = laravel_path('mock_deployment')) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
    }
    $path = $path ? "mock_deployment/$path" : 'mock_deployment';

    return laravel_path($path);
}

function deleteDirectory(string $directory): bool
{
    // Normalize the directory path and ensure it exists
    $directory = rtrim($directory, '/');

    if (!file_exists($directory)) {
        return false;
    }

    // Ensure the path is a directory
    if (!is_dir($directory)) {
        return false;
    }

    // Open the directory
    $files = array_diff(scandir($directory), ['.', '..']);

    foreach ($files as $file) {
        $path = $directory . '/' . $file;

        // Recursively delete directories
        if (is_dir($path)) {
            deleteDirectory($path);
        } elseif (!unlink($path)) {
            return false;
        }
    }

    // Remove the now-empty directory
    if (!rmdir($directory)) {
        return false;
    }

    return true;
}
