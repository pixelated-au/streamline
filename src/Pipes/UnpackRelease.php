<?php

namespace Pixelated\Streamline\Pipes;

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use RuntimeException;
use ZipArchive;

readonly class UnpackRelease implements Pipe
{
    /** ZipArchive::OPSYS_UNIX. Referenced by value so the check does not depend on ext-zip constants. */
    private const int OPSYS_UNIX = 3;

    /** The S_IFMT mask and S_IFLNK value from the unix mode stored in a zip entry's attributes. */
    private const int UNIX_FILE_TYPE_MASK = 0xF000;

    private const int UNIX_SYMLINK = 0xA000;

    public function __construct(private ZipArchive $zip) {}

    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        CommandClassCallback::dispatch('info', 'Unpacking archive');
        $downloadedArchivePath = $builder->getDownloadedArchivePath();
        $tempDir               = $builder->getWorkTempDir();

        if ($this->zip->open($downloadedArchivePath) !== true) {
            throw new RuntimeException("Error: Failed to unpack $downloadedArchivePath");
        }

        try {
            // Validate every entry before writing any of them. extractTo() strips literal "../"
            // segments but will happily follow a symlink entry, letting a later entry be written
            // outside the working directory - and straight into the live installation.
            $this->assertArchiveIsSafe($tempDir);

            $this->zip->extractTo($tempDir);
        } finally {
            $this->zip->close();
        }

        // The archive was downloaded into the working directory, which is moved into place as the
        // new release. Left there it would be deployed into the live site (and the backup).
        if (is_file($downloadedArchivePath) && !@unlink($downloadedArchivePath)) {
            throw new RuntimeException("Error: Could not remove the downloaded archive $downloadedArchivePath after unpacking it");
        }

        return $builder;
    }

    protected function assertArchiveIsSafe(string $tempDir): void
    {
        for ($index = 0; $index < $this->zip->count(); $index++) {
            $name = $this->zip->getNameIndex($index);

            if ($name === false) {
                throw new RuntimeException(
                    "Error: The name of entry #$index in the release archive could not be read. The archive has been refused."
                );
            }

            if ($this->isSymlink($index)) {
                throw new RuntimeException(
                    "Error: The release archive contains a symbolic link ('$name'). Symbolic links are " .
                    'refused because they can be used to write files outside the installation directory.'
                );
            }

            if (!$this->isContainedWithin($tempDir, $name)) {
                throw new RuntimeException(
                    "Error: The release archive contains an entry ('$name') that would be written outside " .
                    'the working directory. The archive has been refused.'
                );
            }
        }
    }

    protected function isSymlink(int $index): bool
    {
        $opsys      = null;
        $attributes = null;

        if (!$this->zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        return $this->isUnixSymlinkMode((int) $opsys, (int) $attributes);
    }

    /**
     * Kept separate from the ZipArchive call so the bit handling can be tested on its own - the
     * out-parameters of getExternalAttributesIndex() cannot be populated by a mock.
     */
    public function isUnixSymlinkMode(int $opsys, int $attributes): bool
    {
        // The symlink bits only carry meaning for entries written on a unix system.
        if ($opsys !== self::OPSYS_UNIX) {
            return false;
        }

        // The unix mode lives in the high 16 bits of the external attributes.
        return (($attributes >> 16) & self::UNIX_FILE_TYPE_MASK) === self::UNIX_SYMLINK;
    }

    protected function isContainedWithin(string $baseDir, string $entryName): bool
    {
        // Absolute and drive-qualified names are never legitimate in a release. extractTo() would
        // neutralise them on this platform, but refusing them keeps the check platform independent.
        if (preg_match('#^([/\\\\]|[A-Za-z]:)#', $entryName) === 1 || str_contains($entryName, "\0")) {
            return false;
        }

        $base   = $this->normalisePath($baseDir);
        $target = $this->normalisePath($baseDir . '/' . $entryName);

        return $target === $base || str_starts_with($target, $base . '/');
    }

    /**
     * Resolves "." and ".." without touching the filesystem, because the entry being checked has
     * deliberately not been written yet.
     */
    protected function normalisePath(string $path): string
    {
        $path       = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($path, '/');
        $segments   = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $segments);
    }
}
