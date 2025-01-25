<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Pixelated\Streamline\Facades\ArchivedReleaseTools;
use Pixelated\Streamline\Facades\GitHubApi;
use Pixelated\Streamline\Services;

it('should not create a temporary directory because it already exists', function () {
    $tempDir  = 'streamline_temp';
    $tempPath = storage_path($tempDir);

    Config::set('streamline.work_temp_dir', $tempDir);

    File::shouldReceive('exists')->once()->with($tempPath)->andReturnTrue();
    File::shouldReceive('makeDirectory')->never();
    File::shouldReceive('isDirectory')->never();

    $archivedReleaseTools = ArchivedReleaseTools::fake();
    $archivedReleaseTools->makeTempDir();

    expect($archivedReleaseTools->tempDir)->toBe(storage_path($tempDir));
});

it('should successfully create a temporary directory when it does not exist', function () {
    $tempDir  = 'streamline_temp';
    $tempPath = storage_path($tempDir);

    Config::set('streamline.work_temp_dir', $tempDir);

    File::shouldReceive('exists')->once()->with($tempPath)->andReturnFalse();
    File::shouldReceive('makeDirectory')->once()->with($tempPath, 0755, true)->andReturnTrue();
    File::shouldReceive('isDirectory')->never();

    $archivedReleaseTools = ArchivedReleaseTools::fake();
    $archivedReleaseTools->makeTempDir();

    expect($archivedReleaseTools->tempDir)->toBe(storage_path($tempDir));
});

it('should throw a RuntimeException when the temporary directory cannot be created', function () {
    Config::set('streamline.work_temp_dir', 'streamline_temp');

    $archivedReleaseTools = ArchivedReleaseTools::fake();

    File::shouldReceive('exists')->andReturnFalse();
    File::shouldReceive('makeDirectory')->andReturnFalse();
    File::shouldReceive('isDirectory')->andReturnFalse();

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessageMatches("(Working directory '(?:[\w\/]+)streamline_temp' could not be created)");

    $archivedReleaseTools->makeTempDir();
});

it('should download the specified version of the archive file', function () {
    $versionToInstall = 'v1.0.0';
    $archiveFileName  = 'release.zip';
    $tempDir          = 'streamline_temp';

    fakeOutputStyle();

    Config::set('streamline.work_temp_dir', $tempDir);
    Config::set('streamline.release_archive_file_name', $archiveFileName);

    GitHubApi::shouldReceive('withWebUrl')
        ->with("releases/download/$versionToInstall/$archiveFileName")
        ->once()
        ->andReturnSelf();

    GitHubApi::shouldReceive('withDownloadPath')
        ->with(storage_path("$tempDir/$archiveFileName"))
        ->once()
        ->andReturnSelf();

    GitHubApi::shouldReceive('withProgressCallback')
        ->once()
        ->andReturnSelf();

    GitHubApi::shouldReceive('get')
        ->once();

    $archivedReleaseTools = ArchivedReleaseTools::fake();
    $archivedReleaseTools->download($versionToInstall);

    expect($archivedReleaseTools->downloadedArchivePath)->toBe(storage_path("$tempDir/$archiveFileName"));
});

it('should handle network errors during the download process', function () {
    $versionToInstall = 'v1.0.0';
    $archiveFileName  = 'release.zip';
    $tempDir          = 'streamline_temp';

    fakeOutputStyle();

    Config::set('streamline.work_temp_dir', $tempDir);
    Config::set('streamline.release_archive_file_name', $archiveFileName);

    $archivedReleaseTools = ArchivedReleaseTools::fake();

    GitHubApi::shouldReceive('withWebUrl')
        ->with("releases/download/$versionToInstall/$archiveFileName")
        ->once()
        ->andReturnSelf();

    GitHubApi::shouldReceive('withDownloadPath')
        ->with(storage_path("$tempDir/$archiveFileName"))
        ->once()
        ->andReturnSelf();

    GitHubApi::shouldReceive('withProgressCallback')
        ->once()
        ->andReturnSelf();

    GitHubApi::shouldReceive('get')
        ->andThrow(new RuntimeException('Error: Failed to connect to GitHub API'));

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Error: Failed to connect to GitHub API');

    $archivedReleaseTools->download('v1.0.0');
});

it('should successfully unpack a valid ZIP archive', function () {
    $this->expectNotToPerformAssertions();

    $tempDir         = 'streamline_temp';
    $archiveFileName = 'release.zip';

    Config::set('streamline.work_temp_dir', $tempDir);
    Config::set('streamline.release_archive_file_name', $archiveFileName);

    $archivedReleaseTools = ArchivedReleaseTools::fake();
    $archivedReleaseTools->makeTempDir();
    $archivedReleaseTools->unpack();
});

it('should throw a RuntimeException when unpacking an invalid or corrupted ZIP file', function () {
    $tempDir         = 'streamline_temp';
    $archiveFileName = 'invalid.zip';

    Config::set('streamline.work_temp_dir', $tempDir);
    Config::set('streamline.release_archive_file_name', $archiveFileName);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Error: Failed to unpack ' . storage_path("$tempDir/$archiveFileName"));

    $zipMock = Mockery::mock(ZipArchive::class);
    $zipMock->shouldReceive('open')->with(storage_path("$tempDir/$archiveFileName"))->andReturnFalse();

    $archivedReleaseTools = new Services\ArchivedReleaseTools($zipMock);
    $archivedReleaseTools->unpack();
});

it('should properly clean up the temporary directory after operations', function () {
    $this->expectNotToPerformAssertions();

    Config::set('streamline.work_temp_dir', 'streamline_temp');
    Config::set('streamline.release_archive_file_name', 'release.zip');

    $archivedReleaseTools = ArchivedReleaseTools::fake();

    File::shouldReceive('deleteDirectory')
        ->once()
        ->with(storage_path('streamline_temp'))
        ->andReturnTrue();

    $archivedReleaseTools->cleanup();
});
