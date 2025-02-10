<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\Event;
use Pixelated\Streamline\Actions\CreateArchive;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;

class BackupCurrentInstallation implements Pipe
{
    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $sourceDir = base_path();
        $backupDir = config('streamline.backup_dir');
        $filename  = 'backup-' . date('Ymd_His') . '.tgz';

        Event::dispatch(new CommandClassCallback('info', "Backing up the current installation to $backupDir/$filename"));

        $createArchive = app()->make(CreateArchive::class, [
            'sourceFolder'    => $sourceDir,
            'destinationPath' => $backupDir,
            'filename'        => $filename,
        ]);

        $createArchive->create();

        return $builder;
    }
}
