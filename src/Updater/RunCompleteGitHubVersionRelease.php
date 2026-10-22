<?php

namespace Pixelated\Streamline\Updater;

use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class RunCompleteGitHubVersionRelease
{
    private string $laravelTempBackupDir;

    /** @var resource */
    private $stream;

    private bool $isTesting;

    private bool $hasSwappedRelease = false;

    /**
     * @param  string[]  $protectedPaths
     * @param  string[]  $carryForwardPaths
     */
    public function __construct(
        private readonly string $tempDirName,
        private readonly string $laravelBasePath,
        private readonly string $publicDirName,
        private readonly string $frontendBuildDir,
        private readonly string $installingVersion,
        private readonly string $composerPath,
        private readonly array $protectedPaths,
        private readonly int $dirPermission,
        private readonly int $filePermission,
        private readonly int $protectedFilePermission,
        private readonly string $oldReleaseArchivePath,
        private readonly bool $doRetainOldReleaseDir = true,
        private readonly bool $doOutput = false,
        private readonly array $carryForwardPaths = [],
    ) {
        $this->laravelTempBackupDir = $this->getNextBackupDirName();
        $this->isTesting            = defined('IS_TESTING');
        $this->stream               = $this->isTesting ? fopen('php://output', 'wb') : STDOUT;
    }

    protected function getNextBackupDirName(): string
    {
        // get a directory listing of the current directory
        $parent = dirname($this->laravelBasePath);

        $matches = [];

        if ($handle = opendir($parent)) {
            try {
                while (($file = readdir($handle)) !== false) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }

                    if (
                        is_dir($parent . '/' . $file)
                        && str_starts_with("$parent/$file", "{$this->laravelBasePath}_old")
                    ) {
                        $matches[] = $file;
                    }
                }
            } finally {
                closedir($handle);
            }
        }

        if (empty($matches)) {
            return $this->laravelBasePath . '_old.0';
        }

        $numbers = array_map($this->backupDirNumber(...), $matches);
        sort($numbers);

        return $this->laravelBasePath . '_old.' . (end($numbers) + 1);
    }

    /**
     * Reads the numeric suffix from a backup directory name. Stripping every non-digit from the
     * whole name instead would fold digits in the application's own directory name into the
     * number, so "app2_old.0" would read as 20 and the next backup would be "app2_old.21".
     */
    protected function backupDirNumber(string $directoryName): int
    {
        return preg_match('/_old\.(\d+)$/', $directoryName, $matches) ? (int) $matches[1] : 0;
    }

    /**
     * @throws Exception
     */
    public function run(): void
    {
        $this->output('Starting update');
        $this->copyFrontEndAssetsFromOldToNewRelease();
        $this->carryForwardRetainedPaths();
        $this->preserveProtectedPaths();
        $this->moveNewReleaseIntoDeployment();

        // From here on the new release is already live. The previous release is deliberately kept
        // until the very end so that a failure can still be undone.
        try {
            $this->runComposerUpdate();
            $this->setEnvVersionNumber();
        } catch (Throwable $exception) {
            // Nothing has touched the database yet, so restoring the previous release is safe.
            $this->rollBackDeployment();

            throw $exception;
        }

        try {
            $this->optimiseNewRelease();
        } catch (Throwable $exception) {
            // Migrations may have partially applied. Rolling back the code would leave the old
            // release running against a new schema, so this is left for the operator to decide.
            $this->output(
                "ERROR: The update failed while optimising/migrating. The previous release has been left in place at $this->laravelTempBackupDir. " .
                'It has NOT been restored automatically because the database may have been partially migrated. ' .
                'Restore it manually only if you have confirmed the database schema still matches it.'
            );

            throw $exception;
        }

        $this->removeOldDeployment();
        $this->terminateBackupArchive();
        $this->output('Update completed');
    }

    protected function copyFrontEndAssetsFromOldToNewRelease(): void
    {
        $existingReleaseBuildDir = "$this->publicDirName/$this->frontendBuildDir";
        $incomingReleaseBuildDir = $this->tempDirName . '/' . basename($this->publicDirName) . '/' . $this->frontendBuildDir;

        $this->output("Copying previous version frontend assets. From: $existingReleaseBuildDir to: $incomingReleaseBuildDir");
        $this->validateDirectoriesExist($existingReleaseBuildDir, $incomingReleaseBuildDir);

        $log = $this->recursiveCopyOldBuildFilesToNewDir($existingReleaseBuildDir, $incomingReleaseBuildDir);

        // This is to ensure the output is in a consistent log order for testing purposes.
        $this->isTesting && sort($log);

        $this->output(implode(PHP_EOL, $log));
    }

    /**
     * Copies configured paths out of the old release into the new one. Anything the incoming
     * release already ships is left alone, so this is safe for directories the release also
     * contains - it is the same rule the front-end build directory is carried across under.
     */
    protected function carryForwardRetainedPaths(): void
    {
        if (!$this->carryForwardPaths) {
            return;
        }

        $this->output('Carrying forward retained paths...');

        $log = [];

        foreach ($this->carryForwardPaths as $carriedPath) {
            $sourcePath      = $this->laravelBasePath . '/' . ltrim($carriedPath, '/');
            $destinationPath = $this->tempDirName . '/' . ltrim($carriedPath, '/');

            if (is_dir($sourcePath)) {
                $log[] = $this->makeDir($destinationPath);
                $log   = [...$log, ...$this->recursiveCopyOldBuildFilesToNewDir($sourcePath, $destinationPath)];
            } elseif (file_exists($sourcePath)) {
                $log[] = $this->copyFile($sourcePath, $destinationPath, false);
            } else {
                $log[] = "  - Warning: Carried forward path not found: $sourcePath";
            }
        }

        // Keeps the output in a stable order for testing, as with the front-end assets above.
        $this->isTesting && sort($log);

        $this->output(implode(PHP_EOL, $log));
        $this->output('Retained paths carried forward.');
    }

    /**
     * @return string[]
     */
    protected function recursiveCopyOldBuildFilesToNewDir(string $source, string $destination): array
    {
        $iterator = new FilesystemIterator($source);
        $log      = [];

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $sourcePath = $item->isLink() ? $item->getLinkTarget() : $item->getPathname();
            $destPath   = $destination . DIRECTORY_SEPARATOR . $item->getFilename();

            if ($item->isDir()) {
                $log[] = $this->makeDir($destPath);
                $log   = [...$log, ...$this->recursiveCopyOldBuildFilesToNewDir($sourcePath, $destPath)];
            } else {
                // This doesn't overwrite because, it's copying the old release files to the new release
                // ...we don't want to overwrite the new release files with their old counterparts.
                $log[] = $this->copyFile($sourcePath, $destPath, false);
            }
        }

        return $log;
    }

    protected function moveNewReleaseIntoDeployment(): void
    {
        $this->output("Moving $this->laravelBasePath to $this->laravelTempBackupDir");

        // rename() returns false (it does not throw) on cross-filesystem moves, permission problems
        // and open handles. Left unchecked, the update carries on against directories that were
        // never actually swapped and reports success while the old release is still being served.
        if (!@rename($this->laravelBasePath, $this->laravelTempBackupDir)) {
            throw new RuntimeException(
                "Error: Could not move the current release ($this->laravelBasePath) to $this->laravelTempBackupDir. " .
                'The deployment has not been modified.'
            );
        }

        $this->output("Moving $this->tempDirName to $this->laravelBasePath");

        if (!@rename($this->tempDirName, $this->laravelBasePath)) {
            // Put the previous release back so the site keeps serving.
            $restored = @rename($this->laravelTempBackupDir, $this->laravelBasePath);

            throw new RuntimeException(
                "Error: Could not move the new release ($this->tempDirName) into $this->laravelBasePath. " .
                ($restored
                    ? 'The previous release has been restored.'
                    : "The previous release could NOT be restored and is still at $this->laravelTempBackupDir. Restore it manually — the site is currently down.")
            );
        }

        $this->hasSwappedRelease = true;
    }

    /**
     * Undoes moveNewReleaseIntoDeployment(), putting the previous release back where it was.
     * Only safe to call before any database migrations have run.
     */
    protected function rollBackDeployment(): void
    {
        if (!$this->hasSwappedRelease) {
            return;
        }

        $this->output('The update failed. Rolling back to the previous release...');

        if (!@rename($this->laravelBasePath, $this->tempDirName)) {
            $this->output(
                "WARNING! Could not move the failed release out of $this->laravelBasePath. " .
                "The previous release is still at $this->laravelTempBackupDir and must be restored manually."
            );

            return;
        }

        if (!@rename($this->laravelTempBackupDir, $this->laravelBasePath)) {
            $this->output(
                "WARNING! Could not restore the previous release from $this->laravelTempBackupDir to $this->laravelBasePath. " .
                'Restore it manually — the site is currently down.'
            );

            return;
        }

        $this->hasSwappedRelease = false;
        $this->output("Rolled back to the previous release. The failed release is at $this->tempDirName.");
    }

    /**
     * @throws Exception
     */
    protected function runComposerUpdate(): void
    {
        $this->output("Running composer install with path: $this->composerPath");

        try {
            // The path is escaped because runCommand() builds a shell string; an unquoted path
            // containing shell metacharacters would otherwise be interpreted rather than executed.
            $this->runCommand(escapeshellarg($this->composerPath) . ' install --no-dev --no-interaction --prefer-install=dist');
        } catch (Exception $exception) {
            $this->output('Composer install failed! Please check the output above for any errors.');

            throw $exception;
        }
    }

    protected function removeOldDeployment(): void
    {
        $this->output("Deleting of $this->laravelTempBackupDir as it's no longer needed");
        $this->deleteDirectory($this->laravelTempBackupDir);
    }

    protected function terminateBackupArchive(): void
    {
        if (!$this->oldReleaseArchivePath) {
            $this->output('No backup archive was recorded for this update, so there is nothing to clean up.');

            return;
        }

        if ($this->doRetainOldReleaseDir) {
            $this->output("Retaining old release backup ($this->oldReleaseArchivePath). Make sure you clean it up manually.");

            return;
        }

        $this->output('Deleting old release backup: ' . basename($this->oldReleaseArchivePath));
        @unlink($this->oldReleaseArchivePath);

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

        $this->output("Copy file from: $realSourcePath to $realDestPath (Permissions: $this->filePermission)");
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
        $this->output("Resetting the CWD to $this->laravelBasePath");
        chdir($this->laravelBasePath);
        $this->output('Running optimisation tasks...');
        $this->runCommand('php artisan optimize:clear');
        $this->output('Optimisation tasks completed.');
        $this->output('Running database migrations...');
        $this->runCommand('php artisan migrate --force');
        $this->output('Migrations completed.');
    }

    private function runCommand(string $command): void
    {
        $this->output("Executing: $command");

        // @codeCoverageIgnoreStart
        if ($this->isTesting) {
            return; // Do not execute commands in tests. We only want to simulate them.
        }
        $output   = null;
        $response = exec("$command 2>&1", $output);

        if ($output) {
            $this->output(implode(PHP_EOL, $output));
        }

        if ($response === false) {
            $this->output("Error executing command: $command");

            throw new RuntimeException("Error executing command: $command\n");
        }

        $this->output("Command ($command) executed successfully.");
        // @codeCoverageIgnoreEnd
    }

    protected function output(string $message): void
    {
        if (!$this->doOutput) {
            return;
        }
        fwrite($this->stream, "$message\n");
    }

    protected function validateDirectoriesExist(string $liveAssetsDir, string $tempWorkingDir): void
    {
        if (!file_exists($this->laravelBasePath)) {
            throw new RuntimeException("Error: Release directory '$this->laravelBasePath' does not exist! This should be the directory that contains your application deployment.");
        }

        if (!$liveAssetsDir || !is_dir($liveAssetsDir)) {
            throw new RuntimeException("Error: Invalid old assets directory: $liveAssetsDir");
        }
        $this->makeDir($tempWorkingDir, 'Error: Could not create assets directory: %s');
    }

    protected function preserveProtectedPaths(): void
    {
        $this->output('Preserving protected paths...');

        $log = [];

        foreach ($this->protectedPaths as $protectedPath) {
            $sourcePath      = $this->laravelBasePath . '/' . ltrim($protectedPath, '/');
            $destinationPath = $this->tempDirName . '/' . ltrim($protectedPath, '/');

            if (is_dir($sourcePath)) {
                $log = [...$log, ...$this->copyDirectory($sourcePath, $destinationPath, $this->protectedFilePermission)];
            } elseif (file_exists($sourcePath)) {
                // Protected files are the ones most likely to hold secrets (.env above all), so
                // they are not given the world-readable permission used for ordinary files.
                $log[] = $this->copyFile($sourcePath, $destinationPath, permission: $this->protectedFilePermission);
            } else {
                $log[] = "  - Warning: Protected path not found: $sourcePath";
            }
        }
        $this->output(implode(PHP_EOL, $log));

        $this->output('Protected paths preserved successfully.');
    }

    /**
     * @return string[]
     */
    protected function copyDirectory(string $source, string $destination, ?int $permission = null): array
    {
        $this->makeDir($destination);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $log = [];

        foreach ($iterator as $item) {
            $targetPath = str_replace($source, $destination, $item->getPathname());

            if ($item->isDir()) {
                $log[] = $this->makeDir($targetPath);
            } else {
                $log[] = $this->copyFile($item->getPathname(), $targetPath, permission: $permission);
            }
        }

        return $log;
    }

    protected function copyFile(
        string $source,
        string $destination,
        bool $doOverwrite = true,
        ?int $permission = null
    ): string {
        $permission ??= $this->filePermission;

        if (!$doOverwrite && file_exists($destination)) {
            $message = "  - Skipped copying $source to $destination. File already exists";

            if (is_writable($destination) && chmod($destination, $permission)) {
                $message .= " (But still set permission: $permission)";
            } else {
                $message .= ' (And could not set the permission because destination is not writable)'; // @codeCoverageIgnore
            }

            return $message;
        }

        $parentDir = dirname($destination);

        if (!file_exists($parentDir)) {
            // The readability being checked is the *source* directory's, so the message names it.
            // It previously reported $parentDir, which is a different directory entirely.
            $sourceDir = dirname($source);

            if (!is_readable($sourceDir)) {
                throw new RuntimeException("Error: $sourceDir cannot be copied as it cannot be read from. Please check permissions.");
            }

            $this->makeDir($parentDir);
        }

        if (!is_readable($source)) {
            throw new RuntimeException("Error: Source file is not readable. Check your permissions: $source");
        }

        if (!@copy($source, $destination)) {
            throw new RuntimeException("Failed to copy file: $source to $destination");
        }

        $chmodSuccess = @chmod($destination, $permission)
            ? "Permission: $permission"
            : 'Permission could not be set'; // @codeCoverageIgnore

        return "  - Copied: $source to $destination ($chmodSuccess)";
    }

    /**
     * Deletes a directory and its contents.
     * Code is based on Laravel's `Illuminate\Filesystem\Filesystem::deleteDirectory` method.
     */
    protected function deleteDirectory(string $directory, bool $preserve = false): bool
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

    public function makeDir(string $targetPath, string $errorMessage = 'Directory "%s" was not created'): string
    {
        if (!@is_dir($targetPath) && !@mkdir($targetPath, $this->dirPermission, true) && !@is_dir($targetPath)) {
            throw new RuntimeException(sprintf($errorMessage, $targetPath));
        }

        chmod($targetPath, $this->dirPermission);

        return "  - Directory created: $targetPath (Permission: $this->dirPermission)";
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
