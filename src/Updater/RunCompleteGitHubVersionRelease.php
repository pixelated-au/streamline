<?php

namespace Pixelated\Streamline\Updater;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

readonly class RunCompleteGitHubVersionRelease
{
    private string $resolvedTempDir;

    public function __construct(
        private ZipArchive $zip,
        private string     $downloadedArchivePath,
        private string     $tempDirName,
        private string     $laravelBasePath,
        private string     $publicDirName,
        private string     $frontendBuildDir,
        private string     $installingVersion,
        private int        $maxFileSize,
        private array      $allowedExtensions,
        private array      $protectedPaths,
        private int        $dirPermission,
        private int        $filePermission,
        private string     $backupDirPath,
        private bool       $doRetainOldReleaseDir = true,
        private bool       $doOutput = false,
    )
    {
        $this->resolvedTempDir = "$this->laravelBasePath/$this->tempDirName";
    }

    public function run(): void
    {
        $this->output('Starting update');
        $this->copyFrontEndAssetsFromOldToNewRelease();
        $this->unpackNewRelease();
        $this->cleanOutInvalidFilesInNewRelease("$this->resolvedTempDir/$this->publicDirName/$this->frontendBuildDir");
        $this->moveNewReleaseIntoDeployment();
        $this->terminateOldReleaseDir();
        $this->setEnvVersionNumber();
        $this->optimiseNewRelease();
        $this->output('Update completed');
    }

    protected function unpackNewRelease(): void
    {
        $this->output('Unpacking archive');

        if ($this->zip->open($this->downloadedArchivePath) === true) {
            $this->zip->extractTo($this->resolvedTempDir);
            $this->zip->close();
        } else {
            throw new RuntimeException("Error: Failed to unpack $this->downloadedArchivePath");
        }
    }

    public function cleanOutInvalidFilesInNewRelease(string $assetsDir): void
    {
        $this->output('Cleaning out invalid files');
        $this->recursivelyRemoveInvalidFiles($assetsDir);
    }

    protected function recursivelyRemoveInvalidFiles(string $assetsDir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($assetsDir, FilesystemIterator::SKIP_DOTS)
        );
        $iterator->rewind();
        /** @var \SplFileInfo $item */
        foreach ($iterator as $key => $item) {
            $extension = strtolower($item->getExtension() ?? '');

            if (!in_array($extension, array_map(static fn($item) => strtolower($item), $this->allowedExtensions), true)) {
                $this->output("Removing file with disallowed extension: $key");
                unlink($key);
                continue;
            }

            if ($item->getSize() > $this->maxFileSize) {
                $this->output("Removing file exceeding size limit: $key");
                unlink($key);
            }
        }
    }

    protected function copyFrontEndAssetsFromOldToNewRelease(): void
    {
        $this->output('Copying frontend assets');
        $oldBuildDir = "$this->laravelBasePath/$this->publicDirName/$this->frontendBuildDir";
        $newBuildDir = "$this->resolvedTempDir/$this->publicDirName/$this->frontendBuildDir";

        $this->validateDirectoriesExist($oldBuildDir, $newBuildDir);

        $this->recursiveCopyOldBuildFilesToNewDir($oldBuildDir, $newBuildDir);
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
        $this->output('Creating backup of release directory');

        $this->moveDirectory($this->laravelBasePath, $this->backupDirPath, true);

        $this->output('Moving downloaded files');
        $this->moveDirectory("$this->backupDirPath/$this->tempDirName", $this->laravelBasePath);
    }

    protected function terminateOldReleaseDir(): void
    {
        if ($this->doRetainOldReleaseDir) {
            $this->output('Retaining old release directory. Make sure you clean it up manually.');
            return;
        }

        $this->output('Deleting old release directory');

        $this->deleteDirectory($this->backupDirPath);

        if (file_exists($this->backupDirPath)) {
            $this->output("WARNING! Could not delete the old release directory: $this->backupDirPath. Continuing with the update...");
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

        chmod($realDestPath, $this->filePermission);
    }

    protected function setEnvVersionNumber(): void
    {
        $this->output("Setting version number in .env file to: $this->installingVersion");
        $envFilePath = $this->laravelBasePath . '/.env';

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
            if (in_array($fileInfo->getFilename(), $this->protectedPaths, true)) {
                continue;
            }

            rename($sourcePath, $destinationPath);
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
        if (!is_dir($directory)) {
            throw new InvalidArgumentException("The $directory does not exist.");
        }

        $items = new FilesystemIterator($directory);

        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->deleteDirectory($item->getPathname());
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
}
