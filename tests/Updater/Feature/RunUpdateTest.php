<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Pixelated\Streamline\Actions\CreateArchive;
use Pixelated\Streamline\Updater\RunCompleteGitHubVersionRelease;

beforeEach(function () {
    $this->ns = 'Pixelated\\Streamline\\Updater';

    $this->deploymentPath = workbench_path();
});

afterEach(function () {
    deleteDirectory(working_path());
    deleteDirectory(laravel_path('mock_deployment.backup.2024-12-25_11-22-33'));
});

it('can run an update using actual filesystem actions and deletes the backup directory from the previous release',
    function () {
        $disk     = Storage::fake('local');
        $tempDisk = Storage::fake('temp');

        $tempDisk->makeDirectory('zip_temp');

        $this->app->bind(
            CreateArchive::class,
            fn (Application $app) => new CreateArchive(
                sourceFolder: $disk->path(''),
                destinationPath: config('streamline.backup_dir'),
                filename: 'backup-' . date('Ymd_His') . '.tgz',
            )
        );

        createNewReleaseFolderFileStructure($tempDisk);
        makeDirsAndFiles($disk, $tempDisk);
        $updater = new RunCompleteGitHubVersionRelease(
            tempDirName: $tempDisk->path('unpacked'),
            laravelBasePath: $disk->path('laravel'),
            publicDirName: $disk->path('laravel/public'),
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
            'Copying frontend assets. From: ' . $disk->path('laravel/public/build') . ' to: ' . $tempDisk->path('unpacked/public/build'),
            '  - Directory created: ' . $tempDisk->path('unpacked/public/build/assets') . ' (permission: 493)',
            '  - Directory created: ' . $tempDisk->path('unpacked/public/build/assets/text-file') . ' (permission: 493)',
            '  - Copied: ' . $disk->path('/laravel/public/build/assets/text-file/existing_file.txt') . ' to ' . $tempDisk->path('/unpacked/public/build/assets/text-file/existing_file.txt') . ' (permission: 420)',
            'Preserving protected paths...',
            '  - Copied: ' . $disk->path('laravel/.env') . ' to ' . $tempDisk->path('unpacked/.env') . ' (permission: 420)',
            'Protected paths preserved successfully.',
            'Moving ' . $disk->path('laravel') . ' to ' . $disk->path('laravel_old'),
            'Moving ' . $tempDisk->path('unpacked') . ' to ' . $disk->path('laravel'),
            'Deleting of ' . $disk->path('laravel_old') . " as it's no longer needed",
            'Setting version number in .env file to: 1.0.0',
            'Version number updated successfully in .env file',
            'Resetting the CWD to ' . $disk->path('laravel'),
            'Running optimisation tasks...',
            'Executing: php artisan optimize:clear',
            'Optimisation tasks completed.',
            'Running database migrations...',
            'Executing: php artisan migrate --force',
            'Migrations completed.',
            'Deleting old release backup: oldArchive.tgz',
            "Update completed\n",
        ];
        $this->expectOutputString(implode("\n", $output));

        $this->assertDirectoryDoesNotExist(laravel_path('mock_deployment.backup_dir'));
        $this->assertFileExists($disk->path('laravel/app/test.php'));
        $this->assertFileExists($disk->path('laravel/public/build/file1.txt'));
        $this->assertFileExists($disk->path('laravel/public/build/file2.txt'));
        $this->assertFileExists($disk->path('laravel/public/build/dir1/file3.txt'));
        $this->assertFileExists($disk->path('laravel/public/build/dir1/dir2/file4.txt'));
        $this->assertFileExists($disk->path('laravel/public/build/file5.txt'));
        $this->assertFileExists($disk->path('laravel/public/build/assets/text-file/existing_file.txt'));
        $this->assertFileExists($disk->path('laravel/.env'));

        $this->assertSame(
            expected: "Prefix...\nSTREAMLINE_APPLICATION_VERSION_INSTALLED=1.0.0\nSuffix...",
            actual: file_get_contents($disk->path('laravel/.env'))
        );
    });

function makeDirsAndFiles(Filesystem $disk, Filesystem $tempDisk): void
{
    // Mock frontend assets directory
    $disk->makeDirectory('laravel/public/build/assets/text-file');
    $disk->put('laravel/public/build/assets/text-file/existing_file.txt',
        'an existing file that should be copied across to the new deployment');

    // Mock Laravel deployment structure
    $disk->makeDirectory('laravel/app');
    $disk->put('laravel/app/test.php', '<?php // Test file');
    $disk->put('laravel/.env', "Prefix...\nSTREAMLINE_APPLICATION_VERSION_INSTALLED=v0.0.0\nSuffix...");

    $tempDisk->put('old_releases/oldArchive.tgz', 'old archive contents');
}

function createNewReleaseFolderFileStructure(Filesystem $tempDisk, ?array $files = null): void
{
    $tempDisk->makeDirectory('unpacked/app');

    $tempDisk->makeDirectory('unpacked/public/build/assets/text-file');
    $tempDisk->put('unpacked/public/build/assets/text-file/existing_file.txt',
        'an existing file that should be copied across to the new deployment');

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
        ->each(fn (string $file) => $tempDisk->put($file, "This file has the name: $file"));
}
