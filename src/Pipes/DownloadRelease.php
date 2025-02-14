<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\Event;
use Pixelated\Streamline\Actions\ProgressMeter;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Facades\GitHubApi;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;

class DownloadRelease implements Pipe
{
    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $versionToInstall = $builder->getNextAvailableRepositoryVersion();
        $downloadedArchiveFileName = config('streamline.release_archive_file_name');
        $downloadedArchivePath = $builder->getWorkTempDir().'/'.$downloadedArchiveFileName;

        Event::dispatch(new CommandClassCallback('info', "Downloading archive for version $versionToInstall"));
        GitHubApi::withWebUrl("releases/download/$versionToInstall/$downloadedArchiveFileName")
            ->withDownloadPath($downloadedArchivePath)
            ->withProgressCallback(resolve(ProgressMeter::class))
            ->get();

        $builder->setDownloadedArchivePath($downloadedArchivePath);

        return $builder;
    }
}
