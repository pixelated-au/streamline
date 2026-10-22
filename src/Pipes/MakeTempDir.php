<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\File;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use RuntimeException;

class MakeTempDir implements Pipe
{
    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $tempDir = $builder->getWorkTempDir();

        CommandClassCallback::dispatch('comment', "Creating temporary directory $tempDir");

        $this->assertSafeToEmpty($tempDir);

        if (!File::exists($tempDir)
            // 0700: the unpacked release passes through here before going live, so other local
            // users have no business reading or writing it.
            && !File::makeDirectory(path: $tempDir, mode: 0700, recursive: true)
            && !File::isDirectory($tempDir)) {
            throw new RuntimeException("Working directory '$tempDir' could not be created");
        }

        $this->empty($tempDir);

        return $builder;
    }

    /**
     * The working directory belongs to Streamline and is emptied at the start of every update, so
     * a configuration that points it at the live site (or anything containing it) must never get
     * as far as deleting anything.
     */
    private function assertSafeToEmpty(string $tempDir): void
    {
        $resolvedTempDir = $this->resolve($tempDir);
        $resolvedBase    = $this->resolve(base_path());

        if ($resolvedTempDir    === '/'
            || $resolvedTempDir === $resolvedBase
            || str_starts_with($resolvedBase . '/', $resolvedTempDir . '/')) {
            throw new RuntimeException(
                "Working directory '$tempDir' is the application directory ($resolvedBase), or one of its parents. " .
                'It is emptied at the start of every update, so it must be a separate directory.'
            );
        }
    }

    private function empty(string $tempDir): void
    {
        if (!File::isDirectory($tempDir)) {
            return;
        }

        // Leftovers from an earlier run, such as a failed release parked here by a rollback, would
        // otherwise be extracted over and reappear in the new release.
        if (!File::cleanDirectory($tempDir)) {
            throw new RuntimeException("Working directory '$tempDir' could not be emptied");
        }
    }

    /**
     * Resolves symlinks and "." / ".." segments, including for a path that does not exist yet (by
     * resolving the nearest existing ancestor).
     */
    private function resolve(string $path): string
    {
        $path      = str_replace('\\', '/', $path);
        $remaining = [];

        while ($path !== '' && $path !== '/' && ($real = realpath($path)) === false) {
            $segment = basename($path);

            if ($segment !== '.' && $segment !== '') {
                array_unshift($remaining, $segment);
            }

            $path = dirname($path);
        }

        $base = $path === '' || $path === '/' ? '/' : (string) realpath($path);
        $full = rtrim(str_replace('\\', '/', $base), '/') . '/' . implode('/', $remaining);

        // Collapse any ".." that survived in the non-existent tail.
        $parts = [];

        foreach (explode('/', $full) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }
}
