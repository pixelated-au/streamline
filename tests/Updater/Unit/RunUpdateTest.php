<?php

use org\bovigo\vfs\vfsStream;
use Pixelated\Streamline\Updater\RunCompleteGitHubVersionRelease;

beforeEach(function () {
    $this->ns            = 'Pixelated\\Streamline\\Updater';
    $this->rootFs        = vfsStream::setup('streamline');
    $this->deploymentDir = vfsStream::newDirectory('mock_deployment');

    $this->rootFs->addChild($this->deploymentDir);
    $this->rootPath       = $this->rootFs->url();
    $this->deploymentPath = $this->deploymentDir->url();
    vfsStream::copyFromFileSystem(workbench_path(), $this->deploymentDir);
});

it('throws an exception that the laravel base directory cannot be found', function () {
    $this->expectExceptionMessage("Error: Release directory 'non-existent-directory' does not exist! This should be the directory that contains your application deployment.");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory(['laravelBasePath' => 'non-existent-directory']);
    $closure   = fn($liveAssetsDir, $oldAssetsDir) => $this->validateDirectoriesExist($liveAssetsDir, $oldAssetsDir);
    $closure->call($runUpdate, "$this->deploymentPath/public/build", "$this->rootPath/temp/public/build");
});

it('throws an exception that the live/old assets directory cannot be found', function () {
    $this->expectExceptionMessage("Error: Invalid old assets directory: $this->deploymentPath/invalid");
    $this->expectException(RuntimeException::class);
    $runUpdate = runUpdateClassFactory();
    $closure   = fn($liveAssetsDir, $oldAssetsDir) => $this->validateDirectoriesExist($liveAssetsDir, $oldAssetsDir);
    $closure->call($runUpdate, "$this->deploymentPath/invalid", "$this->deploymentPath/temp/public/build");
});

it('throws an exception that the temp assets directory cannot be found', function () {
    $this->rootFs->chmod(0000);

    $this->expectExceptionMessage("Error: Could not create assets directory: $this->rootPath/temp/public/build");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory();
    $closure   = fn($liveAssetsDir, $oldAssetsDir) => $this->validateDirectoriesExist($liveAssetsDir, $oldAssetsDir);
    $closure->call($runUpdate, "$this->deploymentPath/public/build", "$this->rootPath/temp/public/build");
});

it('throws an exception when the destination directory is not writeable', function () {
    $this->rootFs->addChild(vfsStream::newDirectory('backup_dir/public/build/assets/NEW_DIRECTORY'));
    $this->deploymentDir->getChild('public/build/assets')?->chmod(0000);

    $this->expectExceptionMessage("Error: Failed to create directory: $this->deploymentPath/public/build/assets/NEW_DIRECTORY");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory();
    $closure   = fn($source, $destination) => $this->recursiveCopyOldBuildFilesToNewDir($source, $destination);
    $closure->call($runUpdate, "$this->rootPath/backup_dir/public/build/assets", "$this->deploymentPath/public/build/assets");
});

it('throws an exception that the source directory does not exist when moving a directory', function () {
    $this->expectExceptionMessage('Source directory (non-existent-directory) does not exist');
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory([
        'laravelBasePath' => 'test-directory',
    ]);
    $closure   = fn(string $source, string $destination, bool $isRoot = false) => $this->moveDirectory($source, $destination, $isRoot);
    $closure->call($runUpdate, 'non-existent-directory', "$this->rootPath/temp/public/build");
});

it('throws an exception that the destination could not be created when moving a directory', function () {
    $this->rootFs->chmod(0000);

    $this->expectExceptionMessage("Directory '$this->rootPath/temp/public/build' was not created");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory([
        'laravelBasePath' => laravel_path(),
    ]);
    $closure   = fn(string $source, string $destination, bool $isRoot = false) => $this->moveDirectory($source, $destination, $isRoot);
    $closure->call($runUpdate, laravel_path(), "$this->rootPath/temp/public/build");
});

it('throws an exception that a destination folder could not be created when moving a directory', function () {
    $this->rootFs->addChild(
        vfsStream::newDirectory('temp/public/build/assets/dir1/no-permissions')
    );
    $this->rootFs->getChild('temp/public/build/assets/dir1/no-permissions')?->chmod(0000);

    $noPermissionsDir = vfsStream::newDirectory('public/build/assets/dir1/no-permissions/dir2');
    /** @var \org\bovigo\vfs\vfsStreamDirectory $dir2 */
    $dir2 = $noPermissionsDir->getChild('public/build/assets/dir1/no-permissions/dir2');
    $dir2->addChild(
        vfsStream::newFile('a-file.txt')
    );
    $this->deploymentDir->addChild($noPermissionsDir);

    $this->expectExceptionMessage("Directory '$this->rootPath/temp/public/build/assets/dir1/no-permissions/dir2' was not created");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory([
        'laravelBasePath' => $this->rootPath,
    ]);

    $closure = fn(string $source, string $destination, bool $isRoot = false) => $this->moveDirectory($source, $destination, $isRoot);
    $closure->call($runUpdate, "$this->deploymentPath/public/build/assets", "$this->rootPath/temp/public/build/assets");
});

it('outputs a notice that the backup directory is being retained', function () {
    $runUpdate = runUpdateClassFactory([
        'doRetainOldReleaseDir' => true,
        'oldReleaseArchivePath' => 'archive.zip',
    ]);
    (fn() => $this->terminateBackupArchive())->call($runUpdate);

    $output = $this->getActualOutputForAssertion();
    $this->assertStringContainsString('Retaining old release backup (archive.zip). Make sure you clean it up manually.', $output);
});

it('outputs a notice that the backup directory could not be deleted despite it being flagged for deletion', function () {
    $this->rootFs->addChild(vfsStream::newDirectory('backup_test'));
    $this->rootFs->addChild(vfsStream::newFile('backup_test/archive.zip'));
    $this->rootFs->getChild('backup_test/archive.zip')?->chmod(0400);

    $runUpdate = runUpdateClassFactory(
        [
            'laravelBasePath'       => $this->rootPath,
            'oldReleaseArchivePath' => "$this->rootPath/backup_test/archive.zip",
        ]
    );
    (fn() => $this->terminateBackupArchive())->call($runUpdate);

    $output = $this->getActualOutputForAssertion();
    $this->assertStringContainsString("Could not delete the old release: $this->rootPath/backup_test", $output);

    $this->assertTrue(
        $this->rootFs->hasChild('backup_test/archive.zip')
    );
});

it('throws an exception that the source file cannot be read when copying assets', function () {
    $file = vfsStream::newFile('temp/public/build/assets/unreadable.txt');
    $this->rootFs->addChild($file->withContent('')->chmod(0000));

    $this->expectExceptionMessage("Error: Source file is not readable: {$file->url()}");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory();
    $closure   = fn(string $realSourcePath, string $realDestPath) => $this->copyAsset($realSourcePath, $realDestPath);
    $closure->call($runUpdate, $file->url(), 'unused_destination');
});

it('throws an exception that the source file cannot be copied for an unknown reason when copying assets', function () {
    $this->disableErrorHandling();

    $file = vfsStream::newFile('temp/public/build/assets/my_asset.txt');
    $this->rootFs->addChild($file->withContent(''));
    $this->rootFs->chmod(0600);

    $this->expectExceptionMessage("Error: Failed to copy file: {$file->url()} to $this->rootPath");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory();
    $closure   = fn(string $realSourcePath, string $realDestPath) => $this->copyAsset($realSourcePath, $realDestPath);
    $closure->call($runUpdate, $file->url(), $this->rootPath);
});

it('cannot find the .env file when setting the current version number', function () {
    $this->startOutputBuffer();
    $this->deploymentDir->removeChild('.env');

    $this->expectExceptionMessage("Error: Environment file ($this->deploymentPath/.env) does not exist in the release directory");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory(['laravelBasePath' => $this->deploymentPath]);
    (fn() => $this->setEnvVersionNumber())->call($runUpdate);
});

it('cannot save the .env file when setting the current version number', function () {
    $this->startOutputBuffer();
    $this->disableErrorHandling();

    $dotEnvFile = $this->deploymentDir->getChild('.env')?->chmod(0400);

    $dotEnvFileContents = file_get_contents($dotEnvFile->url());
    $this->expectExceptionMessage("Error: Failed to update version number in Laravel's .env file");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory(['laravelBasePath' => $this->deploymentPath]);
    (fn() => $this->setEnvVersionNumber())->call($runUpdate);

    $this->assertStringEqualsFile($dotEnvFile->url(), $dotEnvFileContents);
});

it('fails to delete a missing directory', function () {
    $runUpdate = runUpdateClassFactory();
    $result    = (fn($directory) => $this->deleteDirectory($directory))
        ->call($runUpdate, "$this->rootPath/missing_dir");

    expect($result)->toBeTrue();
});


it('calls delete and will return false when the file does not exist', function () {
    $nonExistentFile = vfsStream::url('root/non_existent_file.txt');

    $runUpdate = runUpdateClassFactory();
    $result    = (fn($path) => $this->delete($path))->call($runUpdate, $nonExistentFile);

    expect($result)->toBeFalse();
});


it('should return false when the file exists but cannot be deleted due to permissions', function () {
    $this->disableErrorHandling();

    $file = vfsStream::newFile('bad_permissions.txt');
    $dir  = vfsStream::newDirectory('temp', 0400);
    $dir->addChild($file);

    $this->rootFs->addChild($dir);

    $runUpdate = runUpdateClassFactory();
    $closure = fn(string $path) => $this->delete($path);
    $result  = $closure->call($runUpdate, $file->url());

    expect($result)->toBeFalse()
        ->and($file->url())->toBeReadableFile();
});

it('should handle multiple protected paths with wildcards correctly', function () {
    $runUpdate = runUpdateClassFactory([
        'protectedPaths' => [
            'config/*',
            'storage/logs/*',
            'public/uploads/*',
            'resources/views/*',
        ],
    ]);

    $testCases = [
        'config/app.php'                                               => true,
        'storage/logs/laravel.log'                                     => true,
        'public/uploads/image.jpg'                                     => true,
        'resources/views/welcome.blade.php'                            => true,
        'app/Http/Controllers/Controller.php'                          => false,
        'database/migrations/2023_01_01_000000_create_users_table.php' => false,
    ];

    foreach ($testCases as $path => $expected) {
        $result = (fn(string $relativePath) => $this->isProtectedWildcardPath($relativePath))->call($runUpdate, $path);
        expect($result)->toBe($expected, "Failed assertion for path: $path");
    }
});


it('should correctly match a relative path that is exactly the same as a wildcard protected path without the asterisk', function () {
    $runUpdate = runUpdateClassFactory([
        'protectedPaths' => [
            'config/*',
            'storage/logs/*',
            'public/uploads/*',
        ],
    ]);

    $result = (fn(string $relativePath) => $this->isProtectedWildcardPath($relativePath))->call($runUpdate, 'config/app.php');

    expect($result)->toBeTrue();
});

it('should return false for an empty relative path', function () {
    $runUpdate = runUpdateClassFactory([
        'protectedPaths' => [
            'config/*',
            'storage/logs/*',
            'public/uploads/*',
        ],
    ]);

    $result = (fn(string $relativePath) => $this->isProtectedWildcardPath($relativePath))->call($runUpdate, '');

    expect($result)->toBeFalse();
});

/**
 * @param array{
 *     tempDirName?: string,
 *     laravelBasePath?: string,
 *     publicDirName?: string,
 *     frontendBuildDir?: string,
 *     installingVersion?: string,
 *     maxFileSize?: int,
 *     allowedExtensions?: array<string>,
 *     protectedPaths?: array<string>,
 *     dirPermission?: int,
 *     filePermission?: int,
 *     backupDirPath?: string,
 *     oldReleaseArchivePath?: string,
 *     doRetainOldReleaseDir?: bool,
 *     doOutput?: bool,
 * } $options
 */
function runUpdateClassFactory(array $options = []): RunCompleteGitHubVersionRelease
{
    $options = array_merge(
        [
            'tempDirName'           => 'temp',
            'laravelBasePath'       => laravel_path(),
            'publicDirName'         => 'public',
            'frontendBuildDir'      => 'build',
            'installingVersion'     => '1.0.0',
            'protectedPaths'        => ['.env'],
            'dirPermission'         => 0755,
            'filePermission'        => 0644,
            'oldReleaseArchivePath' => laravel_path('archive.zip'),
            'doRetainOldReleaseDir' => false,
            'downloadedArchivePath' => laravel_path('archive.zip'),
            'doOutput'              => true,
        ],
        $options);

    return new RunCompleteGitHubVersionRelease(
        tempDirName: $options['tempDirName'],
        laravelBasePath: $options['laravelBasePath'],
        publicDirName: $options['publicDirName'],
        frontendBuildDir: $options['frontendBuildDir'],
        installingVersion: $options['installingVersion'],
        protectedPaths: $options['protectedPaths'],
        dirPermission: $options['dirPermission'],
        filePermission: $options['filePermission'],
        oldReleaseArchivePath: $options['oldReleaseArchivePath'],
        doRetainOldReleaseDir: $options['doRetainOldReleaseDir'],
        doOutput: $options['doOutput'],
    );
}
