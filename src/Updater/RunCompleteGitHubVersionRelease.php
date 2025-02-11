<?php

namespace Pixelated\Streamline\Updater;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RuntimeException;

readonly class RunCompleteGitHubVersionRelease
{
    public function __construct(
        private string $tempDirName,
        private string $laravelBasePath,
        private string $publicDirName,
        private string $frontendBuildDir,
        private string $installingVersion,
        private array  $protectedPaths,
        private int    $dirPermission,
        private int    $filePermission,
        private string $oldReleaseArchivePath,
        private bool   $doRetainOldReleaseDir = true,
        private bool   $doOutput = false,
    )
    {
    }

    public function run(): void
    {
        $this->output('Starting update');
        $this->copyFrontEndAssetsFromOldToNewRelease();
        $this->moveNewReleaseIntoDeployment();
        $this->terminateBackupArchive();
        $this->setEnvVersionNumber();
        $this->optimiseNewRelease();
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
            $destPath   = $destination . DIRECTORY_SEPARATOR . $item->getFilename();

            if ($item->isDir()) {
                if (!is_dir($destPath) && !mkdir($destPath, $this->dirPermission, true) && !is_dir($destPath)) {
                    throw new RuntimeException("Error: Failed to create directory: $destPath");
                }
                $this->recursiveCopyOldBuildFilesToNewDir($sourcePath, $destPath);
            } else {
                $this->copyAsset($sourcePath, $destPath);
            }
        }
    }

    protected function moveNewReleaseIntoDeployment(): void
    {
        $this->output("Deleting contents of $this->laravelBasePath to prepare for new release");
        $this->deleteDirectory($this->laravelBasePath, true);

        $this->output("Moving downloaded files from $this->tempDirName to $this->laravelBasePath");
        $this->moveDirectory($this->tempDirName, $this->laravelBasePath);
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
        if (!is_readable($realSourcePath)) {
            throw new RuntimeException("Error: Source file is not readable: $realSourcePath");
        }

        if (!copy($realSourcePath, $realDestPath)) {
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

        if (!file_put_contents($envFilePath, $contents)) {
            throw new RuntimeException("Error: Failed to update version number in Laravel's .env file");
        }

        $this->output('Version number updated successfully in .env file');
    }

    protected function optimiseNewRelease(): void
    {
        $this->output('Running optimisation tasks...');
        $this->runCommand('composer dump-autoload --no-interaction --no-dev --optimize');
        $this->runCommand('php artisan optimize:clear');
        $this->output('Optimisation tasks completed.');
    }

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
        if (!file_exists($this->laravelBasePath)) {
            throw new RuntimeException("Error: Release directory '$this->laravelBasePath' does not exist! This should be the directory that contains your application deployment.");
        }
        if (!$liveAssetsDir || !is_dir($liveAssetsDir)) {
            throw new RuntimeException("Error: Invalid old assets directory: $liveAssetsDir");
        }
        if (!is_dir($tempWorkingDir) && !mkdir($tempWorkingDir, 0755, true) && !is_dir($tempWorkingDir)) {
            throw new RuntimeException("Error: Could not create assets directory: $tempWorkingDir");
        }
    }

    protected function moveDirectory(string $source, string $destination, bool $isRoot = false): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException("Source directory ($source) does not exist");
        }

        if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
            throw new RuntimeException("Directory '$destination' was not created");
        }

        $iterator = new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS);
        $iterator->rewind();

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $sourcePath = $fileInfo->getPathname();

            $relativePath    = str_replace($source, '', $sourcePath);
            $destinationPath = rtrim($destination, '/') . '/' . ltrim($relativePath, '/');
            if ($fileInfo->isDir()) {
                $this->moveDirectory($sourcePath, $destinationPath);
                continue;
            }

            rename($sourcePath, $this->laravelBasePath . $this->commonChildPath($sourcePath, $destinationPath));
        }

        if (!$isRoot) {
            rmdir($source);
        }
    }

    /**
     * Deletes a directory and its contents.
     * Code is based on Laravel's `Illuminate\Filesystem\Filesystem::deleteDirectory` method.
     */
    protected function deleteDirectory($directory, $preserve = false): bool
    {
        if (!file_exists($directory)) {
            return true; // Directory does not exist. No need to delete it.
        }
        $items = new FilesystemIterator($directory);

        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->deleteDirectory($item->getPathname());
                continue;
            }

            $relativePath = $this->intersectPaths($this->laravelBasePath, $item->getPathname());
            if (in_array($relativePath, $this->protectedPaths, true)) {
                continue;
            }

            if ($this->isProtectedWildcardPath($relativePath)) {
                continue;
            }

            $this->delete($item->getPathname());
        }

        unset($items);

        if (!$preserve) {
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

    /**
     * Calculates the relative path between two paths. If using the params below, it will return public/index.html
     * @param string $parentPath e.g., '/var/www/html'
     * @param string $childPath e.g., '/var/www/html/public/index.html'
     */
    protected function intersectPaths(string $parentPath, string $childPath): string
    {
        return str_replace($parentPath, '', $childPath);
    }

    /**
     * Calculates the common path between two paths. If using the params below, it will return 'public/index.html'
     * @param string $path1 - e.g., '/var/www/html'
     * @param string $path2 - e.g., '/var/www/test/public/index.html'
     */
    protected function commonChildPath(string $path1, string $path2): string
    {
        $path1 = array_reverse(explode('/', trim($path1, '/')));
        $path2 = array_reverse(explode('/', trim($path2, '/')));

        $commonPath = [];
        foreach ($path1 as $index => $part) {
            if (isset($path2[$index]) && $path2[$index] === $part) {
                $commonPath[] = $part;
            }
        }

        return trim(implode('/', array_reverse($commonPath)), '/');
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
