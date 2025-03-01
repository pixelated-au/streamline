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
    $closure   = fn ($liveAssetsDir, $oldAssetsDir) => $this->validateDirectoriesExist($liveAssetsDir, $oldAssetsDir);
    $closure->call($runUpdate, "$this->deploymentPath/public/build", "$this->rootPath/temp/public/build");
});

it('throws an exception that the live/old assets directory cannot be found', function () {
    $this->expectExceptionMessage("Error: Invalid old assets directory: $this->deploymentPath/invalid");
    $this->expectException(RuntimeException::class);
    $runUpdate = runUpdateClassFactory();
    $closure   = fn ($liveAssetsDir, $oldAssetsDir) => $this->validateDirectoriesExist($liveAssetsDir, $oldAssetsDir);
    $closure->call($runUpdate, "$this->deploymentPath/invalid", "$this->deploymentPath/temp/public/build");
});

it('throws an exception that the temp assets directory cannot be found', function () {
    $this->rootFs->chmod(0000);

    $this->expectExceptionMessage("Error: Could not create assets directory: $this->rootPath/temp/public/build");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory();
    $closure   = fn ($liveAssetsDir, $oldAssetsDir) => $this->validateDirectoriesExist($liveAssetsDir, $oldAssetsDir);
    $closure->call($runUpdate, "$this->deploymentPath/public/build", "$this->rootPath/temp/public/build");
});

it('throws an exception when the destination directory is not writeable', function () {
    $this->rootFs->addChild(vfsStream::newDirectory('backup_dir/public/build/assets/NEW_DIRECTORY'));
    $assetsDir = $this->deploymentDir->getChild('public/build/assets');
    $this->assertNotNull($assetsDir);
    $assetsDir->chmod(0444);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Directory \"$this->deploymentPath/public/build/assets/NEW_DIRECTORY\" was not created");

    $runUpdate = runUpdateClassFactory();
    $closure   = fn ($source, $destination) => $this->recursiveCopyOldBuildFilesToNewDir($source, $destination);
    $closure->call($runUpdate, "$this->rootPath/backup_dir/public/build/assets",
        "$this->deploymentPath/public/build/assets");
});

it('outputs a notice that the backup directory is being retained', function () {
    $runUpdate = runUpdateClassFactory([
        'doRetainOldReleaseDir' => true,
        'oldReleaseArchivePath' => 'archive.zip',
    ]);
    (fn () => $this->terminateBackupArchive())->call($runUpdate);

    $output = $this->getActualOutputForAssertion();
    $this->assertStringContainsString('Retaining old release backup (archive.zip). Make sure you clean it up manually.',
        $output);
});

it('outputs a notice that the backup directory could not be deleted despite it being flagged for deletion',
    function () {
        $this->rootFs->addChild(vfsStream::newDirectory('backup_test'));
        $this->rootFs->addChild(vfsStream::newFile('backup_test/archive.zip'));
        $this->rootFs->getChild('backup_test/archive.zip')?->chmod(0400);

        $runUpdate = runUpdateClassFactory(
            [
                'laravelBasePath'       => $this->rootPath,
                'oldReleaseArchivePath' => "$this->rootPath/backup_test/archive.zip",
            ]
        );
        (fn () => $this->terminateBackupArchive())->call($runUpdate);

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
    $closure   = fn (string $realSourcePath, string $realDestPath) => $this->copyAsset($realSourcePath, $realDestPath);
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
    $closure   = fn (string $realSourcePath, string $realDestPath) => $this->copyAsset($realSourcePath, $realDestPath);
    $closure->call($runUpdate, $file->url(), $this->rootPath);
});

it('cannot find the .env file when setting the current version number', function () {
    $this->startOutputBuffer();
    $this->deploymentDir->removeChild('.env');

    $this->expectExceptionMessage("Error: Environment file ($this->deploymentPath/.env) does not exist in the release directory");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory(['laravelBasePath' => $this->deploymentPath]);
    (fn () => $this->setEnvVersionNumber())->call($runUpdate);
});

it('cannot save the .env file when setting the current version number', function () {
    $this->startOutputBuffer();
    $this->disableErrorHandling();

    $dotEnvFile = $this->deploymentDir->getChild('.env')?->chmod(0400);

    $dotEnvFileContents = file_get_contents($dotEnvFile->url());
    $this->expectExceptionMessage("Error: Failed to update version number in Laravel's .env file");
    $this->expectException(RuntimeException::class);

    $runUpdate = runUpdateClassFactory(['laravelBasePath' => $this->deploymentPath]);
    (fn () => $this->setEnvVersionNumber())->call($runUpdate);

    $this->assertStringEqualsFile($dotEnvFile->url(), $dotEnvFileContents);
});

it('fails to delete a missing directory', function () {
    $runUpdate = runUpdateClassFactory();
    $result    = (fn ($directory) => $this->deleteDirectory($directory))
        ->call($runUpdate, "$this->rootPath/missing_dir");

    expect($result)->toBeTrue();
});

it('calls delete and will return false when the file does not exist', function () {
    $nonExistentFile = vfsStream::url('root/non_existent_file.txt');

    $this->disableErrorHandling();
    $runUpdate = runUpdateClassFactory();
    $result    = (fn ($path) => $this->delete($path))->call($runUpdate, $nonExistentFile);

    expect($result)->toBeFalse();
});

it('should return false when the file exists but cannot be deleted due to permissions', function () {
    $this->disableErrorHandling();

    $file = vfsStream::newFile('bad_permissions.txt');
    $dir  = vfsStream::newDirectory('temp', 0400);
    $dir->addChild($file);

    $this->rootFs->addChild($dir);

    $runUpdate = runUpdateClassFactory();
    $closure   = fn (string $path) => $this->delete($path);
    $result    = $closure->call($runUpdate, $file->url());

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
        $result = (fn (string $relativePath) => $this->isProtectedWildcardPath($relativePath))->call($runUpdate, $path);
        expect($result)->toBe($expected, "Failed assertion for path: $path");
    }
});

it('should correctly match a relative path that is exactly the same as a wildcard protected path without the asterisk',
    function () {
        $runUpdate = runUpdateClassFactory([
            'protectedPaths' => [
                'config/*',
                'storage/logs/*',
                'public/uploads/*',
            ],
        ]);

        $result = (fn (string $relativePath) => $this->isProtectedWildcardPath($relativePath))->call($runUpdate,
            'config/app.php');

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

    $result = (fn (string $relativePath) => $this->isProtectedWildcardPath($relativePath))->call($runUpdate, '');

    expect($result)->toBeFalse();
});

it('should preserve protected paths', function () {
    $this->rootFs   = vfsStream::setup();
    $this->rootPath = vfsStream::url('root');
    $protectedDir   = vfsStream::newDirectory('protected_dir/sub');

    /** @noinspection PhpPossiblePolymorphicInvocationInspection */
    $protectedDir->getChild('sub')?->addChild(vfsStream::newFile('protected_by_parent_file.txt'));
    $this->rootFs->addChild($protectedDir);
    $this->rootFs->addChild(vfsStream::newDirectory('un-protected_dir'));
    $subDir = vfsStream::newDirectory('sub/directory');
    $this->rootFs->addChild($subDir);
    $this->rootFs->addChild(vfsStream::newFile('protected_file.txt'));
    $this->rootFs->addChild(vfsStream::newFile('un-protected_file.txt'));

    /** @noinspection PhpPossiblePolymorphicInvocationInspection */
    $subDir->getChild('directory')?->addChild(vfsStream::newFile('protected_child_file.txt'));
    $this->rootFs->addChild(vfsStream::newDirectory('temp'));

    $runUpdate = runUpdateClassFactory([
        'laravelBasePath' => $this->rootPath,
        'tempDirName'     => "$this->rootPath/temp",
        'protectedPaths'  => ['protected_dir/sub', 'protected_file.txt', 'sub/directory/protected_child_file.txt'],
    ]);

    (fn () => $this->preserveProtectedPaths())->call($runUpdate);

    expect(is_dir("$this->rootPath/temp/protected_dir"))->toBeTrue()
        ->and(file_exists("$this->rootPath/temp/protected_dir"))->toBeTrue()
        ->and(file_exists("$this->rootPath/temp/protected_dir/sub/protected_by_parent_file.txt"))->toBeTrue()
        ->and(file_exists("$this->rootPath/temp/protected_file.txt"))->toBeTrue()
        ->and(file_exists("$this->rootPath/temp/sub/directory/protected_child_file.txt"))->toBeTrue();

    $output = $this->getActualOutputForAssertion();
    expect($output)->toContain('Preserving protected paths...')
        ->and($output)->toContain('Protected paths preserved successfully.');
});

it('should preserve protected paths when they exist as files', function () {
    $this->rootFs   = vfsStream::setup();
    $this->rootPath = vfsStream::url('root');

    $laravel = vfsStream::newDirectory('laravel');
    $temp    = vfsStream::newDirectory('temp');
    $this->rootFs->addChild($laravel);
    $this->rootFs->addChild($temp);
    vfsStream::newFile('protected.txt')->at($laravel)->setContent('Protected content');
    vfsStream::newFile('unprotected.txt')->at($laravel)->setContent('Un-Protected content');
    vfsStream::newFile('new.txt')->at($temp)->setContent('New content');

    $runUpdate = runUpdateClassFactory([
        'tempDirName'           => $temp->url(),
        'laravelBasePath'       => $laravel->url(),
        'protectedPaths'        => ['protected.txt'],
        'dirPermission'         => 0755,
        'filePermission'        => 0644,
        'oldReleaseArchivePath' => 'archive.zip',
        'doRetainOldReleaseDir' => true,
        'doOutput'              => false,
    ]);

    (fn () => $this->preserveProtectedPaths())->call($runUpdate);

    expect(file_exists("{$temp->url()}/protected.txt"))->toBeTrue()
        ->and(file_get_contents("{$temp->url()}/protected.txt"))->toBe('Protected content')
        ->and(file_exists("{$temp->url()}/new.txt"))->toBeTrue()
        ->and(file_exists("{$temp->url()}/unprotected.txt"))->toBeFalse()
        ->and($temp->getChildren())->toHaveCount(2);
});

it('outputs a warning when a protected path is not found', function () {
    $this->rootFs->addChild(vfsStream::newDirectory('deployment'));
    $deploymentPath = vfsStream::url('streamline/deployment');

    $runUpdate = runUpdateClassFactory([
        'laravelBasePath' => $deploymentPath,
        'protectedPaths'  => ['non_existent_path'],
        'doOutput'        => true,
    ]);

    $this->expectOutputString(
        "Preserving protected paths...\n" .
        "Warning: Protected path not found: $deploymentPath/non_existent_path\n" .
        "Protected paths preserved successfully.\n"
    );

    (fn () => $this->preserveProtectedPaths())->call($runUpdate);
});

it('throws an exception when the destination directory is not writable during directory copy', function () {
    $this->rootFs   = vfsStream::setup();
    $this->rootPath = vfsStream::url('root');
    $this->rootFs->addChild(vfsStream::newDirectory('source'));
    $this->rootFs->addChild(vfsStream::newDirectory('destination')->chmod(0444));

    $runUpdate = runUpdateClassFactory([
        'laravelBasePath' => "$this->rootPath/source",
        'tempDirName'     => "$this->rootPath/destination",
        'protectedPaths'  => ['protected_dir'],
    ]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Directory "vfs://streamline/destination" was not created');

    $closure = fn (string $source, string $destination) => $this->copyDirectory($source, $destination);
    $closure->call($runUpdate, 'vfs://streamline/source', 'vfs://streamline/destination');
});

it('should handle large directories with many nested subdirectories', function () {
    $this->startOutputBuffer();
    $this->expectsOutput();
    $this->rootFs = vfsStream::setup('streamline');
    $source       = vfsStream::newDirectory('source')->at($this->rootFs);
    $destination  = vfsStream::newDirectory('destination')->at($this->rootFs);

    createNestedDirectories($source, 2, 3);

    $runUpdate = runUpdateClassFactory();
    $closure   = fn ($src, $dest) => $this->copyDirectory($src, $dest);
    $closure->call($runUpdate, $source->url(), $destination->url());

    assertDirectoriesEqual($source, $destination);
});

it('throws an exception when the destination directory is not writable during file copy', function () {
    $this->disableErrorHandling();
    $this->rootFs = vfsStream::setup('streamline');
    $sourceDir    = vfsStream::newDirectory('source');
    $this->rootFs->addChild($sourceDir);
    $destinationDir = vfsStream::newDirectory('destination');
    $this->rootFs->addChild($destinationDir);
    $sourceFile = vfsStream::newFile('test.txt')->at($sourceDir);
    $destinationDir->chmod(0000);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Failed to copy file: {$sourceFile->url()} to {$destinationDir->url()}");

    $runUpdate = runUpdateClassFactory();
    $closure   = fn (string $source, string $destination) => $this->copyFile($source, $destination);
    $closure->call($runUpdate, $sourceFile->url(), $destinationDir->url() . '/' . $sourceFile->getName());
});

function createNestedDirectories($dir, $depth, $filesPerDir, $currentDepth = 0): void
{
    for ($i = 0; $i < $filesPerDir; $i++) {
        vfsStream::newFile("file_{$currentDepth}_$i.txt")->at($dir)->setContent("Content $i");
    }

    if ($currentDepth >= $depth) {
        return;
    }

    for ($i = 0; $i < $filesPerDir; $i++) {
        $dirDepth = $currentDepth + 1;
        $subdir   = vfsStream::newDirectory("subdir_{$dirDepth}_$i")->at($dir);
        createNestedDirectories($subdir, $depth, $filesPerDir, $currentDepth + 1);
    }
}

it('should successfully copy frontend assets from existing deployment to new release (in temp dir)', function () {
    // Setup directories
    $this->rootFs->addChild(vfsStream::newDirectory('public/build/assets'));
    $this->rootFs->addChild(vfsStream::newDirectory('temp/public/build/assets'));

    /** @var \org\bovigo\vfs\vfsStreamDirectory $assetsDir */
    $assetsDir = $this->deploymentDir->getChild('public/build');
    // Not adding a manifest.json in the test because it exists on the filesystem in 'workbench/public/build'
    $assetsDir->addChild(vfsStream::newFile('assets/app.css')->withContent('test css content'));
    $assetsDir->addChild(vfsStream::newFile('assets/app.js')->withContent('test js content'));

    // Create and run the update
    $runUpdate = runUpdateClassFactory([
        'laravelBasePath'  => $this->deploymentPath,
        'publicDirName'    => $this->deploymentPath . '/public',
        'frontendBuildDir' => 'build',
        'tempDirName'      => $this->rootPath . '/temp',
    ]);

    $this->startOutputBuffer();
    (fn () => $this->copyFrontEndAssetsFromOldToNewRelease())->call($runUpdate);

    // Verify files were copied
    $this->assertDirectoryExists("$this->rootPath/temp/public/build/assets");
    $this->assertFileExists("$this->rootPath/temp/public/build/manifest.json", 'is copied across from filesystem');
    $this->assertFileExists("$this->rootPath/temp/public/build/assets/app.css");
    $this->assertFileExists("$this->rootPath/temp/public/build/assets/app.js");

    $this->assertJson(file_get_contents("$this->rootPath/temp/public/build/manifest.json"));
    $this->assertStringEqualsFile("$this->rootPath/temp/public/build/assets/app.css", 'test css content');
    $this->assertStringEqualsFile("$this->rootPath/temp/public/build/assets/app.js", 'test js content');

    // Verify proper logging
    $output = $this->getActualOutputForAssertion();
    $this->assertStringContainsString('Copying frontend assets', $output);
    $this->assertStringContainsString('Directory created', $output);
    $this->assertStringContainsString('Copied:', $output);
});

function assertDirectoriesEqual($expected, $actual): void
{
    $expectedIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($expected->url(), FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $actualIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($actual->url(), FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $expectedPaths = iterator_to_array($expectedIterator);
    $actualPaths   = iterator_to_array($actualIterator);

    expect(count($expectedPaths))->toBe(count($actualPaths));

    foreach ($expectedPaths as $path => $expectedFile) {
        $relativePath = substr($path, strlen($expected->url()));
        $actualPath   = $actual->url() . $relativePath;

        expect(file_exists($actualPath))->toBeTrue();

        if ($expectedFile->isDir()) {
            expect(is_dir($actualPath))->toBeTrue();
        } else {
            expect(is_file($actualPath))->toBeTrue()
                ->and(file_get_contents($actualPath))->toBe(file_get_contents($path));
        }
    }
}

/**
 * @param  array{
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
 * }  $options
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
