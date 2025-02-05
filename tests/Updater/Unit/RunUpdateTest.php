<?php

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\visitor\vfsStreamStructureVisitor;
use Pixelated\Streamline\Testing\Mocks\ZipArchiveFake;
use Pixelated\Streamline\Updater\RunCompleteGitHubVersionRelease;

beforeEach(function () {
    $this->ns            = 'Pixelated\\Streamline\\Updater';
    $this->rootFs        = vfsStream::setup('streamline');
    $this->deploymentDir = vfsStream::newDirectory('mock_deployment');

    $this->rootFs->addChild($this->deploymentDir);
    $this->rootPath       = $this->rootFs->url();
    $this->deploymentPath = $this->deploymentDir->url();
//    vfsStream::inspect(new \org\bovigo\vfs\visitor\vfsStreamPrintVisitor());
    vfsStream::copyFromFileSystem(workbench_path(), $this->deploymentDir);
});

it('throws an exception that the laravel base directory cannot be found', function () {
    $this->expectExceptionMessage("Error: Release directory 'non-existent-directory' does not exist! This should be the directory that contains your application deployment.");
    $this->expectException(RuntimeException::class);

    $closure = fn($liveAssetsDir, $oldAssetsDir) => $this->validateDirectoriesExist($liveAssetsDir, $oldAssetsDir);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory(['laravelBasePath' => 'non-existent-directory']),
        "$this->deploymentPath/public/build", "$this->rootPath/temp/public/build"
    );
});

it('throws an exception that the live/old assets directory cannot be found', function () {
    $this->expectExceptionMessage("Error: Invalid old assets directory: $this->deploymentPath/invalid");
    $this->expectException(RuntimeException::class);

    $closure = fn($liveAssetsDir, $oldAssetsDir) => $this->validateDirectoriesExist($liveAssetsDir, $oldAssetsDir);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory(),
        "$this->deploymentPath/invalid", "$this->deploymentPath/temp/public/build"
    );
});

it('throws an exception that the temp assets directory cannot be found', function () {
    $this->rootFs->chmod(0000);

    $this->expectExceptionMessage("Error: Could not create assets directory: $this->rootPath/temp/public/build");
    $this->expectException(RuntimeException::class);

    $closure = fn($liveAssetsDir, $oldAssetsDir) => $this->validateDirectoriesExist($liveAssetsDir, $oldAssetsDir);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory(),
        "$this->deploymentPath/public/build", "$this->rootPath/temp/public/build"
    );
});

it('throws an exception when the destination directory is not writeable', function () {
    $this->rootFs->addChild(vfsStream::newDirectory('backup_dir/public/build/assets/NEW_DIRECTORY'));
    $this->deploymentDir->getChild('public/build/assets')?->chmod(0000);

    $this->expectExceptionMessage("Error: Failed to create directory: $this->deploymentPath/public/build/assets/NEW_DIRECTORY");
    $this->expectException(RuntimeException::class);

    $closure = fn($source, $destination) => $this->recursiveCopyOldBuildFilesToNewDir($source, $destination);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory(),
        "$this->rootPath/backup_dir/public/build/assets", "$this->deploymentPath/public/build/assets"
    );
});

it('throws an exception when the archive release cannot be found', function () {
    $this->startOutputBuffer();

    $this->expectExceptionMessage('Error: Failed to unpack ' . laravel_path('archive.zip'));
    $this->expectException(RuntimeException::class);
    $closure = fn() => $this->unpackNewRelease();

    callPrivateFunction(
        $closure,
        runUpdateClassFactory(['zip' => new ZipArchive()]),
    );
});

it('cleans out invalid file extensions', function () {
    $this->startOutputBuffer();
    /** @var \org\bovigo\vfs\vfsStreamDirectory $assetsDir */
    $assetsDir = $this->deploymentDir->getChild('public/build/assets');
    vfsStream::create([
        'invalid1.txt' => 'Content invalid1.txt', // to be removed
        'valid1.png'   => 'Content valid1.png',
        'dir1'         =>
            ['valid1.png'   => 'Content dir1/valid1.png',
             'valid2.jpg'   => 'Content dir1/valid2.jpg',
             'invalid2.txt' => 'Content dir1/invalid2.txt', // to be removed
             'dir2'         =>
                 ['valid1.png'   => 'Content dir1/dir2/valid1.png',
                  'invalid3.txt' => 'Content dir1/dir2/invalid3.txt', // to be removed
                  'valid2.jpg'   => 'Content dir1/dir2/valid2.jpg',
                 ],
            ],
        'valid2.jpg'   => 'Content valid2.jpg',
    ], $assetsDir);

//    $this->app->setBasePath($this->deploymentPath);

    $closure = fn(string $assetDir) => $this->recursivelyRemoveInvalidFiles($assetDir);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory(['allowedExtensions' => ['png', 'jpg']]),
        "$this->deploymentPath/public/build/assets",
    );

    $expected = [
        'assets' => [
            'valid1.png' => 'Content valid1.png',
            'dir1'       =>
                ['valid1.png' => 'Content dir1/valid1.png',
                 'valid2.jpg' => 'Content dir1/valid2.jpg',
                 'dir2'       =>
                     ['valid1.png' => 'Content dir1/dir2/valid1.png',
                      'valid2.jpg' => 'Content dir1/dir2/valid2.jpg',
                     ],
                ],
            'valid2.jpg' => 'Content valid2.jpg',
        ],
    ];

    /** @var vfsStreamStructureVisitor $visitor */
    $visitor = vfsStream::inspect(new vfsStreamStructureVisitor(), $assetsDir);

    $this->assertEquals($expected, $visitor->getStructure());
});

it('cleans out invalid file sizes', function () {
    $this->startOutputBuffer();
    $assetsDir = vfsStream::newDirectory('/public/build/assets');
    $this->deploymentDir->addChild($assetsDir);
    $assetsDir = $this->deploymentDir->getChild('public/build/assets');

    vfsStream::create([
        'invalid1.jpg' => 'Content invalid1.jpg - abcdefghijklmnopqrstuvwxyz', // to be removed
        'valid1.png'   => 'Content valid1.png',
        'dir1'         =>
            ['valid1.png'   => 'Content dir1/valid1.png',
             'valid2.jpg'   => 'Content dir1/valid2.jpg',
             'invalid2.jpg' => 'Content dir1/invalid2.jpg - abcdefghijklmnopqrstuvwxyz', // to be removed
             'dir2'         =>
                 ['valid1.png'   => 'Content dir1/dir2/valid1.png',
                  'invalid3.jpg' => 'Content dir1/dir2/invalid3.jpg - abcdefghijklmnopqrstuvwxyz', // to be removed
                  'valid2.jpg'   => 'Content dir1/dir2/valid2.jpg',
                 ],
            ],
        'valid2.jpg'   => 'Content valid2.jpg',
    ], $assetsDir);

    callPrivateFunction(
        (fn(string $assetDir) => $this->recursivelyRemoveInvalidFiles($assetDir)),
        runUpdateClassFactory(['maxFileSize' => 30]),
        "$this->deploymentPath/public/build/assets",
    );

    $expected = [
        'assets' => [
            'valid1.png' => 'Content valid1.png',
            'dir1'       =>
                ['valid1.png' => 'Content dir1/valid1.png',
                 'valid2.jpg' => 'Content dir1/valid2.jpg',
                 'dir2'       =>
                     ['valid1.png' => 'Content dir1/dir2/valid1.png',
                      'valid2.jpg' => 'Content dir1/dir2/valid2.jpg',
                     ],
                ],
            'valid2.jpg' => 'Content valid2.jpg',
        ],
    ];

    /** @var vfsStreamStructureVisitor $visitor */
    $visitor = vfsStream::inspect(new vfsStreamStructureVisitor(), $assetsDir);

    $this->assertEquals($expected, $visitor->getStructure());
});

it('throws an exception that the source directory does not exist when moving a directory', function () {
    $this->expectExceptionMessage('Source directory (non-existent-directory) does not exist');
    $this->expectException(RuntimeException::class);

    $closure = fn(string $source, string $destination, bool $isRoot = false) => $this->moveDirectory($source, $destination, $isRoot);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory([
            'laravelBasePath' => 'test-directory',
        ]),
        'non-existent-directory', "$this->rootPath/temp/public/build"
    );
});

it('throws an exception that the destination could not be created when moving a directory', function () {
    $this->rootFs->chmod(0000);

    $this->expectExceptionMessage("Directory '$this->rootPath/temp/public/build' was not created");
    $this->expectException(RuntimeException::class);

    $closure = fn(string $source, string $destination, bool $isRoot = false) => $this->moveDirectory($source, $destination, $isRoot);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory([
            'laravelBasePath' => laravel_path(),
        ]),
        laravel_path(), "$this->rootPath/temp/public/build"
    );
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

    $closure = fn(string $source, string $destination, bool $isRoot = false) => $this->moveDirectory($source, $destination, $isRoot);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory([
            'laravelBasePath' => $this->rootPath,
        ]),
        "$this->deploymentPath/public/build/assets", "$this->rootPath/temp/public/build/assets"
    );
});

it('outputs a notice that the backup directory is being retained', function () {
//    $this->expectOutputRegex('Retaining old release directory\. Make sure you clean it up manually\.');

    callPrivateFunction(
        (fn() => $this->terminateOldReleaseDir()),
        runUpdateClassFactory(['doRetainOldReleaseDir' => true])
    );

    $output = $this->getActualOutputForAssertion();
    $this->assertStringContainsString('Retaining old release directory. Make sure you clean it up manually.', $output);
});

it('outputs a notice that the backup directory could not be deleted despite it being flagged for deletion', function () {
    $this->rootFs->addChild(vfsStream::newDirectory('backup_test/public/build/assets/'));
    $this->rootFs->getChild('backup_test/public/build/assets')?->chmod(6000);

    callPrivateFunction(
        (fn() => $this->terminateOldReleaseDir()),
        runUpdateClassFactory(
            [
                'laravelBasePath' => $this->rootPath,
                'backupDirPath'   => "$this->rootPath/backup_test",
            ]
        ));

    $output = $this->getActualOutputForAssertion();
    $this->assertStringContainsString("Could not delete the old release directory: $this->rootPath/backup_test", $output);


    $this->assertTrue(
        $this->rootFs->hasChild('backup_test/public/build/assets')
    );
});

it('throws an exception that the source file cannot be read when copying assets', function () {
    $file = vfsStream::newFile('temp/public/build/assets/unreadable.txt');
    $this->rootFs->addChild($file->withContent('')->chmod(0000));

    $this->expectExceptionMessage("Error: Source file is not readable: {$file->url()}");
    $this->expectException(RuntimeException::class);

    $closure = fn(string $realSourcePath, string $realDestPath) => $this->copyAsset($realSourcePath, $realDestPath);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory(),
        $file->url(), 'unused_destination'
    );
});

it('throws an exception that the source file cannot be copied for an unknown reason when copying assets', function () {
    $this->disableErrorHandling();

    $file = vfsStream::newFile('temp/public/build/assets/my_asset.txt');
    $this->rootFs->addChild($file->withContent(''));
    $this->rootFs->chmod(0600);

    $this->expectExceptionMessage("Error: Failed to copy file: {$file->url()} to $this->rootPath");
    $this->expectException(RuntimeException::class);

    $closure = fn(string $realSourcePath, string $realDestPath) => $this->copyAsset($realSourcePath, $realDestPath);
    callPrivateFunction(
        $closure,
        runUpdateClassFactory(),
        $file->url(), $this->rootPath
    );
});

it('cannot find the .env file when setting the current version number', function () {
    $this->startOutputBuffer();
    $this->deploymentDir->removeChild('.env');

    $this->expectExceptionMessage("Error: Environment file ($this->deploymentPath/.env) does not exist in the release directory");
    $this->expectException(RuntimeException::class);

    callPrivateFunction(
        (fn() => $this->setEnvVersionNumber()),
        runUpdateClassFactory(['laravelBasePath' => $this->deploymentPath])
    );
});

it('cannot save the .env file when setting the current version number', function () {
    $this->startOutputBuffer();
    $this->disableErrorHandling();

    $dotEnvFile = $this->deploymentDir->getChild('.env')?->chmod(0400);

    $dotEnvFileContents = file_get_contents($dotEnvFile->url());
    $this->expectExceptionMessage("Error: Failed to update version number in Laravel's .env file");
    $this->expectException(RuntimeException::class);

    callPrivateFunction(
        (fn() => $this->setEnvVersionNumber()),
        runUpdateClassFactory(['laravelBasePath' => $this->deploymentPath])
    );

    $this->assertStringEqualsFile($dotEnvFile->url(), $dotEnvFileContents);
});

it('fails to delete a missing directory', function () {
    $this->expectExceptionMessage("The $this->rootPath/missing_dir does not exist.");
    $this->expectException(InvalidArgumentException::class);

    callPrivateFunction(
        (fn(string $directory) => $this->deleteDirectory($directory)),
        runUpdateClassFactory(),
        "$this->rootPath/missing_dir"
    );
});

/**
 * @param array{
 *     zip?: ZipArchiveFake,
 *     downloadedArchivePath?: string,
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
 *     doRetainOldReleaseDir?: bool,
 *     doOutput?: bool,
 * } $options
 */
function runUpdateClassFactory(array $options = []): RunCompleteGitHubVersionRelease
{
    $options = array_merge(
        [
            'zip'                   => new ZipArchiveFake(),
            'downloadedArchivePath' => laravel_path('archive.zip'),
            'tempDirName'           => 'temp',
            'laravelBasePath'       => laravel_path(),
            'publicDirName'         => 'public',
            'frontendBuildDir'      => 'build',
            'installingVersion'     => '1.0.0',
            'maxFileSize'           => 1024 * 1024 * 10, // 10MB
            'allowedExtensions'     => ['png', 'jpg'],
            'protectedPaths'        => ['.env'],
            'dirPermission'         => 0755,
            'filePermission'        => 0644,
            'backupDirPath'         => 'backup_test',
            'doRetainOldReleaseDir' => false,
            'doOutput'              => true,
        ],
        $options);

    return new RunCompleteGitHubVersionRelease(
        zip: $options['zip'],
        downloadedArchivePath: $options['downloadedArchivePath'],
        tempDirName: $options['tempDirName'],
        laravelBasePath: $options['laravelBasePath'],
        publicDirName: $options['publicDirName'],
        frontendBuildDir: $options['frontendBuildDir'],
        installingVersion: $options['installingVersion'],
        maxFileSize: $options['maxFileSize'],
        allowedExtensions: $options['allowedExtensions'],
        protectedPaths: $options['protectedPaths'],
        dirPermission: $options['dirPermission'],
        filePermission: $options['filePermission'],
        backupDirPath: $options['backupDirPath'],
        doRetainOldReleaseDir: $options['doRetainOldReleaseDir'],
        doOutput: $options['doOutput'],
    );
}
