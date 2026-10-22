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

        foreach ($this->expandPaths($this->carryForwardPaths, $log) as $carriedPath) {
            $sourcePath      = $this->laravelBasePath . '/' . $carriedPath;
            $destinationPath = $this->tempDirName . '/' . $carriedPath;

            if (is_link($sourcePath)) {
                $log[] = $this->copySymlink($sourcePath, $destinationPath, false);
            } elseif (is_dir($sourcePath)) {
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
            $sourcePath = $item->getPathname();
            $destPath   = $destination . DIRECTORY_SEPARATOR . $item->getFilename();

            if ($item->isLink()) {
                // Recreated as a link, never followed: a linked directory is not copied as a real one
                // and a link loop cannot recurse.
                $log[] = $this->copySymlink($sourcePath, $destPath, false);
            } elseif ($item->isDir()) {
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
            // --working-dir is explicit as well as the chdir in runCommand(): the process started in the
            // directory that has since been renamed to the backup name, so relying on the inherited
            // working directory would install the dependencies into the release being discarded.
            $this->runCommand(
                escapeshellarg($this->composerPath) .
                ' install --no-dev --no-interaction --prefer-install=dist --working-dir=' .
                escapeshellarg($this->laravelBasePath)
            );
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

        if ($contents === false) {
            throw new RuntimeException("Error: Failed to read Laravel's .env file ($envFilePath)");
        }

        $key         = 'STREAMLINE_APPLICATION_VERSION_INSTALLED';
        $replacement = "$key=$this->installingVersion";
        $count       = 0;
        $updated     = preg_replace_callback(
            pattern: '/^' . $key . '=[^\r\n]*/m',
            callback: static fn() => $replacement,
            subject: $contents,
            count: $count
        );

        if ($updated === null) {
            throw new RuntimeException("Error: Failed to update version number in Laravel's .env file");
        }

        if ($count === 0) {
            // The key is missing: add it rather than silently leaving the old version recorded.
            $updated = $contents . ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . "$replacement\n";
        }

        if (file_put_contents($envFilePath, $updated) === false) {
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

    /**
     * Runs a command inside the new release. A non-zero exit code fails the step.
     *
     * @throws RuntimeException
     */
    protected function runCommand(string $command): void
    {
        $this->output("Executing: $command");

        if ($this->shouldSimulateCommands()) {
            return; // Do not execute commands in tests. We only want to simulate them.
        }

        // The process was started in the directory that is now parked as the backup, so commands
        // must be pointed at the live (new) release explicitly.
        if (!@chdir($this->laravelBasePath)) {
            throw new RuntimeException("Error: Could not change into $this->laravelBasePath to run: $command\n");
        }

        [$exitCode, $output] = $this->executeCommand($command);

        if ($output) {
            $this->output(implode(PHP_EOL, $output));
        }

        if ($exitCode !== 0) {
            $this->output("Error executing command: $command (exit code $exitCode)");

            throw new RuntimeException("Error executing command: $command (exit code $exitCode)\n");
        }

        $this->output("Command ($command) executed successfully.");
    }

    /**
     * Test seam: when true, commands are only logged, not run.
     */
    protected function shouldSimulateCommands(): bool
    {
        return $this->isTesting;
    }

    /**
     * Test seam: the only place a process is started.
     *
     * @return array{0: int, 1: string[]} The exit code and the combined output lines. A command that
     *                                    could not be started is reported as exit code 127.
     */
    protected function executeCommand(string $command): array
    {
        $output   = [];
        $exitCode = 0;

        if (exec("$command 2>&1", $output, $exitCode) === false) {
            return [127, $output];
        }

        return [$exitCode, $output];
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

        foreach ($this->expandPaths($this->protectedPaths, $log) as $protectedPath) {
            $sourcePath      = $this->laravelBasePath . '/' . $protectedPath;
            $destinationPath = $this->tempDirName . '/' . $protectedPath;

            if (is_link($sourcePath)) {
                $log[] = $this->copySymlink($sourcePath, $destinationPath, true);
            } elseif (is_dir($sourcePath)) {
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
            $targetPath = $destination . substr($item->getPathname(), strlen($source));

            if ($item->isLink()) {
                // RecursiveDirectoryIterator does not descend into links, so this is only the link itself.
                $log[] = $this->copySymlink($item->getPathname(), $targetPath, true);
            } elseif ($item->isDir()) {
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

        // Keep the original mtime so carried-forward assets don't look newer than the current build
        // (the asset cleanup ranks revisions by mtime).
        $mtime = @filemtime($source);

        if ($mtime !== false) {
            @touch($destination, $mtime);
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
        return $this->matchesWildcard($relativePath, $this->protectedPaths);
    }

    /**
     * @param  string[]  $patterns
     */
    protected function matchesWildcard(string $relativePath, array $patterns): bool
    {
        if ($relativePath === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if ($this->isWildcard($pattern) && fnmatch($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    protected function isWildcard(string $path): bool
    {
        return strpbrk($path, '*?[') !== false;
    }

    /**
     * Normalises configured paths to clean, base-relative paths and expands wildcard patterns
     * against the OLD release. Empty entries and '.' (which would select the whole installation)
     * are ignored, as is anything that could step outside the base path.
     *
     * Wildcards are resolved one path segment at a time with scandir()/fnmatch() rather than glob(),
     * so they work on any stream wrapper and never scan more than the pattern names.
     *
     * @param  string[]  $paths
     * @param  string[]  $log
     * @return string[]
     */
    protected function expandPaths(array $paths, array &$log): array
    {
        $expanded = [];

        foreach ($paths as $path) {
            $segments = array_values(array_filter(
                explode('/', str_replace('\\', '/', trim($path))),
                static fn(string $segment) => $segment !== '' && $segment !== '.'
            ));

            if (!$segments) {
                continue; // '', '.', './' - never "the whole installation"
            }

            if (in_array('..', $segments, true)) {
                $log[] = "  - Warning: Ignoring path outside the installation: $path";

                continue;
            }

            if (!$this->isWildcard(implode('/', $segments))) {
                $expanded[] = implode('/', $segments);

                continue;
            }

            $matches = $this->expandWildcardSegments($this->laravelBasePath, $segments);

            if (!$matches) {
                $log[] = "  - Warning: No paths match the pattern: $path";
            }

            array_push($expanded, ...$matches);
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @param  string[]  $segments
     * @return string[]
     */
    private function expandWildcardSegments(string $directory, array $segments, string $relative = ''): array
    {
        $segment = array_shift($segments);
        $matches = [];

        if (!$this->isWildcard($segment)) {
            $names = file_exists("$directory/$segment") || is_link("$directory/$segment") ? [$segment] : [];
        } else {
            $entries = is_dir($directory) && !is_link($directory) ? (scandir($directory) ?: []) : [];
            $names   = array_filter(
                $entries,
                static fn(string $name) => $name !== '.' && $name !== '..'
                                                         && (str_starts_with($segment, '.') || !str_starts_with($name, '.'))
                                                         && fnmatch($segment, $name)
            );
        }

        foreach ($names as $name) {
            $path = $relative === '' ? $name : "$relative/$name";

            if ($segments) {
                array_push($matches, ...$this->expandWildcardSegments("$directory/$name", $segments, $path));
            } else {
                $matches[] = $path;
            }
        }

        return $matches;
    }

    /**
     * Recreates a symbolic link, preserving its target verbatim (relative targets stay relative).
     */
    protected function copySymlink(string $source, string $destination, bool $doOverwrite): string
    {
        $target = readlink($source);

        if ($target === false) {
            throw new RuntimeException("Error: Could not read the symbolic link: $source");
        }

        if (is_link($destination) || file_exists($destination)) {
            if (!$doOverwrite) {
                return "  - Skipped linking $source to $destination. Path already exists";
            }

            if (is_dir($destination) && !is_link($destination)) {
                throw new RuntimeException("Error: Cannot replace the directory $destination with a symbolic link");
            }

            @unlink($destination);
        }

        $parentDir = dirname($destination);

        if (!is_dir($parentDir)) {
            $this->makeDir($parentDir);
        }

        if (!@symlink($target, $destination)) {
            throw new RuntimeException("Failed to create symbolic link: $destination -> $target");
        }

        return "  - Linked: $destination -> $target";
    }
}
