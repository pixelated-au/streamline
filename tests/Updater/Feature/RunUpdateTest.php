<?php

use Pixelated\Streamline\Updater\RunUpdate;
beforeEach(function () {
    $this->ns = 'Pixelated\\Streamline\\Updater';

    $this->deploymentPath = workbench_path();
});

afterEach(function () {
    deleteDirectory(working_path());
    deleteDirectory(laravel_path('mock_deployment.backup.2024-12-25_11-22-33'));
});

it('can run an update using actual filesystem actions and deletes the backup directory from the previous release', function () {
    $zipFileWorkingDir = working_path('zip_file_working_temp');
    mkdir($zipFileWorkingDir);
    $newReleaseArchiveFileName = 'archive.zip';

    $zip = createTestableZipFile($zipFileWorkingDir, $newReleaseArchiveFileName);
    makeDirsAndFiles();

    $output = [
        'Starting update',
        'Copying frontend assets',
        'Unpacking archive',
        'Cleaning out invalid files',
        'Removing file with disallowed extension: ' . working_path('temp/public/build/bad.foo'),
        'Removing file with disallowed extension: ' . working_path('temp/public/build/dir1/bad.foo'),
        'Creating backup of release directory',
        'Moving downloaded files',
        'Deleting old release directory',
        'Setting version number in .env file to: 1.0.0',
        'Version number updated successfully in .env file',
        'Running optimisation tasks...',
        'Executing: composer dump-autoload --no-interaction --no-dev --optimize',
        'Executing: php artisan optimize:clear',
        'Optimisation tasks completed.',
        "Update completed\n",
    ];
    $this->expectOutputString(implode("\n", $output));

    $updater = new RunUpdate(
        zip: $zip,
        downloadedArchivePath: working_path('archive.zip'),
        tempDirName: 'temp',
        laravelBasePath: working_path(),
        publicDirName: 'public',
        frontendBuildDir: 'build',
        installingVersion: '1.0.0',
        maxFileSize: 1024 * 1024 * 10, // 10MB
        allowedExtensions: ['txt', 'png', 'jpg'],
        protectedPaths: ['.env'],
        dirPermission: 0755,
        filePermission: 0644,
        backupDirPath: 'backup_dir',
        doRetainOldReleaseDir: false,
        doOutput: true,
    );
    $updater->run();

    $this->assertDirectoryDoesNotExist(laravel_path('mock_deployment.backup_dir'));
    $this->assertFileExists(working_path('app/test.php'));
    $this->assertFileExists(mock_public_path('build/file1.txt'));
    $this->assertFileExists(mock_public_path('build/file2.txt'));
    $this->assertFileExists(mock_public_path('build/dir1/file3.txt'));
    $this->assertFileExists(mock_public_path('build/dir1/dir2/file4.txt'));
    $this->assertFileExists(mock_public_path('build/file5.txt'));
    $this->assertFileExists(working_path('.env'));
    $this->assertFileExists(mock_public_path('build/assets/text-file/existing_file.txt'));

    $this->assertFileDoesNotExist(mock_public_path('build/bad.foo'));
    $this->assertFileDoesNotExist(mock_public_path('build/dir1/bad.foo'));

    $this->assertSame(
        expected: "Prefix...\nSTREAMLINE_APPLICATION_VERSION_INSTALLED=1.0.0\nSuffix...",
        actual: file_get_contents(working_path('.env'))
    );
});

function makeDirsAndFiles(): void
{
    // Mock frontend assets directory
    mkdir(directory:  mock_public_path('build/assets/text-file'), recursive: true);
    file_put_contents(mock_public_path('build/assets/text-file/existing_file.txt'), 'an existing file that should be copied across to the new deployment');

    // Zip file unpacking location
    mkdir(directory:  working_path('temp/build'), recursive: true);

    // Mock Laravel deployment structure
    mkdir(directory:  working_path('app'), recursive: true);
    file_put_contents(working_path('app/test.php'), '<?php // Test file');
    file_put_contents(working_path('.env'), "Prefix...\nSTREAMLINE_APPLICATION_VERSION_INSTALLED=v0.0.0\nSuffix...");
}
