<?php

namespace Pixelated\Streamline\Updater;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

readonly class RunCompleteGitHubVersionRelease
{
    private string $laravelTempBackupDir;

    public function __construct(
        private string $tempDirName,
        private string $laravelBasePath,
        private string $publicDirName,
        private string $frontendBuildDir,
        private string $installingVersion,
        private array $protectedPaths,
        private int $dirPermission,
        private int $filePermission,
        private string $oldReleaseArchivePath,
        private bool $doRetainOldReleaseDir = true,
        private bool $doOutput = false,
    ) {
        $this->laravelTempBackupDir = "{$this->laravelBasePath}_old";
    }

    public function run(): void
    {
        $this->output('Starting update');
        $this->copyFrontEndAssetsFromOldToNewRelease();
        $this->preserveProtectedPaths();
        $this->moveNewReleaseIntoDeployment();
        $this->removeOldDeployment();
        $this->setEnvVersionNumber();
        $this->optimiseNewRelease();
        $this->terminateBackupArchive();
        $this->output('Update completed');
    }

    protected function copyFrontEndAssetsFromOldToNewRelease(): void
    {
        $existingReleaseBuildDir = "$this->publicDirName/$this->frontendBuildDir";
        $incomingReleaseBuildDir = $this->tempDirName . '/' . basename($this->publicDirName) . '/' . $this->frontendBuildDir;

        $this->output("Copying frontend assets. From: $existingReleaseBuildDir to: $incomingReleaseBuildDir");
        $this->validateDirectoriesExist($existingReleaseBuildDir, $incomingReleaseBuildDir);

        $this->recursiveCopyOldBuildFilesToNewDir($existingReleaseBuildDir, $incomingReleaseBuildDir);
    }

    protected function recursiveCopyOldBuildFilesToNewDir(string $source, string $destination): void
    {
        $iterator = new FilesystemIterator($source);
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $sourcePath = $item->isLink() ? $item->getLinkTarget() : $item->getPathname();
            $destPath = $destination . DIRECTORY_SEPARATOR . $item->getFilename();

            if ($item->isDir()) {
                $this->makeDir($destPath);
                $this->recursiveCopyOldBuildFilesToNewDir($sourcePath, $destPath);
            } else {
                $this->copyAsset($sourcePath, $destPath);
            }
        }
    }

    protected function moveNewReleaseIntoDeployment(): void
    {
        $this->output("Moving $this->laravelBasePath to $this->laravelTempBackupDir");
        rename($this->laravelBasePath, $this->laravelTempBackupDir);
        $this->output("Moving $this->tempDirName to $this->laravelBasePath");
        rename($this->tempDirName, $this->laravelBasePath);
    }

    protected function removeOldDeployment(): void
    {
        $this->output("Deleting of $this->laravelTempBackupDir as it's no longer needed");
        $this->deleteDirectory($this->laravelTempBackupDir);
    }

    protected function terminateBackupArchive(): void
    {
        $filename = pathinfo($this->oldReleaseArchivePath)['basename'];
        if ($this->doRetainOldReleaseDir) {
            $this->output("Retaining old release backup ($filename). Make sure you clean it up manually.");

            return;
        }

        $this->output("Deleting old release backup: $filename");
        unlink($this->oldReleaseArchivePath);

        if (file_exists($this->oldReleaseArchivePath)) {
            $this->output("WARNING! Could not delete the old release: $this->oldReleaseArchivePath. Continuing with the update...");
        }
    }

    protected function copyAsset(string $realSourcePath, string $realDestPath): void
    {
        if (! is_readable($realSourcePath)) {
            throw new RuntimeException("Error: Source file is not readable: $realSourcePath");
        }

        if (! copy($realSourcePath, $realDestPath)) {
            throw new RuntimeException("Error: Failed to copy file: $realSourcePath to $realDestPath");
        }
        $this->output("Chmod file: $realDestPath to $this->filePermission");
        chmod($realDestPath, $this->filePermission);
    }

    protected function setEnvVersionNumber(): void
    {
        $this->output("Setting version number in .env file to: $this->installingVersion");
        $envFilePath = rtrim($this->laravelBasePath, '/') . '/.env';

        if (file_exists($envFilePath) === false) {
            throw new RuntimeException("Error: Environment file ($envFilePath) does not exist in the release directory");
        }

        $contents = file_get_contents($envFilePath);
        $contents = preg_replace(
            pattern: '/STREAMLINE_APPLICATION_VERSION_INSTALLED=\S+/',
            replacement: 'STREAMLINE_APPLICATION_VERSION_INSTALLED=' . $this->installingVersion,
            subject: $contents
        );

        if (! file_put_contents($envFilePath, $contents)) {
            throw new RuntimeException("Error: Failed to update version number in Laravel's .env file");
        }

        $this->output('Version number updated successfully in .env file');
    }

    protected function optimiseNewRelease(): void
    {
        $this->output("Resetting the CWD to $this->laravelBasePath");
        chdir($this->laravelBasePath);
        $this->output('Running optimisation tasks...');
        $this->runCommand('php artisan optimize:clear');
        $this->output('Optimisation tasks completed.');
    }

    /** @noinspection PhpSameParameterValueInspection */
    private function runCommand(string $command): void
    {
        $this->output("Executing: $command");
        // @codeCoverageIgnoreStart
        if (defined('IS_TESTING')) {
            return; // Do not execute commands in tests. We only want to simulate them.
        }
        $response = system($command);

        if ($response === false) {
            throw new RuntimeException("Error executing command: $command\n");
        }

        $this->output('Command executed successfully.');
        // @codeCoverageIgnoreEnd
    }

    protected function output(string $message): void
    {
        if ($this->doOutput) {
            printf("$message\n");
            flush();
        }
    }

    protected function validateDirectoriesExist(string $liveAssetsDir, string $tempWorkingDir): void
    {
        if (! file_exists($this->laravelBasePath)) {
            throw new RuntimeException("Error: Release directory '$this->laravelBasePath' does not exist! This should be the directory that contains your application deployment.");
        }
        if (! $liveAssetsDir || ! is_dir($liveAssetsDir)) {
            throw new RuntimeException("Error: Invalid old assets directory: $liveAssetsDir");
        }
        $this->makeDir($tempWorkingDir, 'Error: Could not create assets directory: %s');
    }

    protected function preserveProtectedPaths(): void
    {
        $this->output('Preserving protected paths...');

        foreach ($this->protectedPaths as $protectedPath) {
            $sourcePath = $this->laravelBasePath . '/' . ltrim($protectedPath, '/');
            $destinationPath = $this->tempDirName . '/' . ltrim($protectedPath, '/');
            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destinationPath);
            } elseif (file_exists($sourcePath)) {
                $this->copyFile($sourcePath, $destinationPath);
            } else {
                $this->output("Warning: Protected path not found: $sourcePath");
            }
        }

        $this->output('Protected paths preserved successfully.');
    }

    protected function copyDirectory(string $source, string $destination): void
    {
        $this->makeDir($destination);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = str_replace($source, $destination, $item->getPathname());

            if ($item->isDir()) {
                $this->makeDir($targetPath);
            } else {
                $this->copyFile($item->getPathname(), $targetPath);
            }
        }
    }

    protected function copyFile(string $source, string $destination): void
    {
        if (! file_exists(dirname($destination))) {
            $this->makeDir(dirname($destination));
        }

        if (! @copy($source, $destination)) {
            throw new RuntimeException("Failed to copy file: $source to $destination");
        }

        chmod($destination, $this->filePermission);
        $this->output("Copied: $source to $destination");
    }

    /**
     * Deletes a directory and its contents.
     * Code is based on Laravel's `Illuminate\Filesystem\Filesystem::deleteDirectory` method.
     */
    protected function deleteDirectory($directory, $preserve = false): bool
    {
        if (! file_exists($directory)) {
            return true; // Directory does not exist. No need to delete it.
        }
        $items = new FilesystemIterator($directory);

        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                $this->deleteDirectory($item->getPathname());

                continue;
            }

            $this->delete($item->getPathname());
        }

        unset($items);

        if (! $preserve) {
            @rmdir($directory);
        }

        return true;
    }

    protected function delete(string $path): bool
    {
        if (@unlink($path)) {
            clearstatcache(false, $path);

            return true;
        }

        return false;
    }

    public function makeDir(string $targetPath, string $errorMessage = 'Directory "%s" was not created'): void
    {
        if (! @is_dir($targetPath) && ! @mkdir($targetPath, $this->dirPermission, true) && ! @is_dir($targetPath)) {
            throw new RuntimeException(sprintf($errorMessage, $targetPath));
        }
    }

    protected function isProtectedWildcardPath(string $relativePath): bool
    {
        foreach ($this->protectedPaths as $protectedPath) {
            if (str_ends_with($protectedPath, '*')) {
                $wildcardPath = rtrim($protectedPath, '*');
                if (str_starts_with($relativePath, $wildcardPath)) {
                    return true;
                }
            }
        }

        return false;
    }
}
