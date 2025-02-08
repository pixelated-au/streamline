<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Pixelated\Streamline\Updater\RunCompleteGitHubVersionRelease;

beforeEach(function () {
    $this->ns = 'Pixelated\\Streamline\\Updater';

    $this->deploymentPath = workbench_path();
});

afterEach(function () {
    deleteDirectory(working_path());
    deleteDirectory(laravel_path('mock_deployment.backup.2024-12-25_11-22-33'));
});

it('can run an update using actual filesystem actions and deletes the backup directory from the previous release', function () {
    $disk     = Storage::fake('local');
    $tempDisk = Storage::fake('temp');


    $tempDisk->makeDirectory('zip_temp');

    createNewReleaseFolderFileStructure($tempDisk);
    makeDirsAndFiles($disk, $tempDisk);

    $updater = new RunCompleteGitHubVersionRelease(
        tempDirName: $tempDisk->path('unpacked'),
        laravelBasePath: $disk->path(''),
        publicDirName: $disk->path('public'),
        frontendBuildDir: 'build',
        installingVersion: '1.0.0',
        protectedPaths: ['.env'],
        dirPermission: 0755,
        filePermission: 0644,
        oldReleaseArchivePath: $tempDisk->path('/old_releases/oldArchive.tgz'),
        doRetainOldReleaseDir: false,
        doOutput: true,
    );
    $updater->run();

    $output = [
        'Starting update',
        'Copying frontend assets',
        'Chmod file: ' . $tempDisk->path('/unpacked/public/build/assets/text-file/existing_file.txt') . ' to 420',
        'Deleting contents of ' . $disk->path('') . ' to prepare for new release',
        'Moving downloaded files from ' . $tempDisk->path('unpacked') . ' to ' . $disk->path(''),
        'Deleting old release backup: oldArchive.tgz',
        'Setting version number in .env file to: 1.0.0',
        'Version number updated successfully in .env file',
        'Running optimisation tasks...',
        'Executing: composer dump-autoload --no-interaction --no-dev --optimize',
        'Executing: php artisan optimize:clear',
        'Optimisation tasks completed.',
        "Update completed\n",
    ];
    $this->expectOutputString(implode("\n", $output));


    $this->assertDirectoryDoesNotExist(laravel_path('mock_deployment.backup_dir'));
    $this->assertFileExists($disk->path('app/test.php'));
    $this->assertFileExists($disk->path('public/build/file1.txt'));
    $this->assertFileExists($disk->path('public/build/file2.txt'));
    $this->assertFileExists($disk->path('public/build/dir1/file3.txt'));
    $this->assertFileExists($disk->path('public/build/dir1/dir2/file4.txt'));
    $this->assertFileExists($disk->path('public/build/file5.txt'));
    $this->assertFileExists($disk->path('.env'));
    $this->assertFileExists($disk->path('public/build/assets/text-file/existing_file.txt'));

    $this->assertSame(
        expected: "Prefix...\nSTREAMLINE_APPLICATION_VERSION_INSTALLED=1.0.0\nSuffix...",
        actual: file_get_contents($disk->path('.env'))
    );
});

function makeDirsAndFiles(Filesystem $disk, Filesystem $tempDisk): void
{
    // Mock frontend assets directory
    $disk->makeDirectory('public/build/assets/text-file');
    $disk->put('public/build/assets/text-file/existing_file.txt', 'an existing file that should be copied across to the new deployment');

    // Mock Laravel deployment structure
    $disk->makeDirectory('app');
    $disk->put('app/test.php', '<?php // Test file');
    $disk->put('.env', "Prefix...\nSTREAMLINE_APPLICATION_VERSION_INSTALLED=v0.0.0\nSuffix...");

    $tempDisk->put('old_releases/oldArchive.tgz', 'old archive contents');
}

function createNewReleaseFolderFileStructure(Filesystem $tempDisk, ?array $files = null): void
{
    $tempDisk->makeDirectory('unpacked/app');

    $tempDisk->makeDirectory('unpacked/public/build/assets/text-file');
    $tempDisk->put('unpacked/public/build/assets/text-file/existing_file.txt', 'an existing file that should be copied across to the new deployment');

    $tempDisk->put('unpacked/app/test.php', '<?php // Test file');
    $tempDisk->put('unpacked/.env', "Prefix...\nSTREAMLINE_APPLICATION_VERSION_INSTALLED=v0.0.0\nSuffix...");

    collect($files ?? [
        'unpacked/app/test.php',
        'unpacked/public/build/file1.txt',
        'unpacked/public/build/file2.txt',
        'unpacked/public/build/dir1/file3.txt',
        'unpacked/public/build/dir1/dir2/file4.txt',
        'unpacked/public/build/file5.txt',
    ])
        ->each(fn(string $file) => $tempDisk->put($file, "This file has the name: $file"));
}
