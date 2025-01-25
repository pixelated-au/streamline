<?php

namespace Pixelated\Streamline\Services;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Pixelated\Streamline\Actions\ProgressMeter;
use Pixelated\Streamline\Facades\GitHubApi;
use RuntimeException;
use ZipArchive;

/** @noinspection PhpClassCanBeReadonlyInspection */
/** @deprecated  */
class ArchivedReleaseTools
{
    public readonly string $tempDir;
    public readonly string $downloadedArchiveFileName;
    public readonly string $downloadedArchivePath;

    public function __construct(private readonly ZipArchive $zip, private readonly ?OutputStyle $output = null)
    {
        $this->tempDir                   = storage_path(config('streamline.work_temp_dir'));
        $this->downloadedArchiveFileName = config('streamline.release_archive_file_name');
        $this->downloadedArchivePath     = "$this->tempDir/$this->downloadedArchiveFileName";
    }

    public function makeTempDir(): void
    {
        $this->output?->comment("Creating temporary directory: $this->tempDir");
        if (!File::exists($this->tempDir) && !File::makeDirectory(path: $this->tempDir, recursive: true) && !File::isDirectory($this->tempDir)) {
            throw new RuntimeException("Working directory '$this->tempDir' could not be created");
        }
    }

    public function download(string $versionToInstall): void
    {
        $this->output?->comment("Downloading archive for version $versionToInstall");
        GitHubApi::withWebUrl("releases/download/$versionToInstall/$this->downloadedArchiveFileName")
            ->withDownloadPath($this->downloadedArchivePath)
            ->withProgressCallback(resolve(ProgressMeter::class))
            ->get();
    }

    public function unpack(): void
    {
        $this->output?->comment('Unpacking archive');
        if ($this->zip->open($this->downloadedArchivePath) === true) {
            $this->zip->extractTo($this->tempDir);
            $this->zip->close();
        } else {
            throw new RuntimeException("Error: Failed to unpack $this->downloadedArchivePath");
        }
    }

    public function cleanup(): void
    {
        File::deleteDirectory($this->tempDir);
    }
}
