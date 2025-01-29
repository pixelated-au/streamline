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

function callPrivateFunction(Closure $method, $updater, ...$parameters)
{
    return $method->call($updater, ...$parameters);
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

function mock_public_path(string $path = ''): string
{
    $path = $path ? "public/$path" : 'public';
    return working_path($path);
}

function createTestableZipFile(string $zipFileWorkingDir, string $newReleaseArchiveFileName, ?array $files = null): ZipArchive
{
    $zip = new ZipArchive();
    $zip->open(working_path($newReleaseArchiveFileName), ZipArchive::CREATE);

    collect($files ?? [
        'app/test.php',
        'public/build/file1.txt',
        'public/build/file2.txt',
        'public/build/dir1/file3.txt',
        'public/build/dir1/dir2/file4.txt',
        'public/build/dir1/bad.foo',
        'public/build/file5.txt',
        'public/build/bad.foo',
    ])
        ->each(function (string $file) use ($zip) {
//            File::makeDirectory(dirname("$zipFileWorkingDir/$file"), recursive: true, force: true);
//            File::put(path:"$zipFileWorkingDir/$file", contents: "This file has the name: $file");
            $zip->addFromString($file, "This file has the name: $file");
        })
        ->tap(fn() => $zip->close())
//        ->each(fn(string $file) => unlink("$zipFileWorkingDir/$file"))
        ->tap(fn() => deleteDirectory($zipFileWorkingDir));

    return $zip;
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
        } else if (!unlink($path)) {
            return false;
        }
    }

    // Remove the now-empty directory
    if (!rmdir($directory)) {
        return false;
    }

    return true;
}
