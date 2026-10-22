<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Contracts\Container\BindingResolutionException;
use Pixelated\Streamline\Actions\CreateArchive;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;

readonly class BackupCurrentInstallation implements Pipe
{
    public function __construct(private CreateArchive $createArchive) {}

    /**
     * @throws BindingResolutionException
     */
    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $this->createArchive->create();

        // The filename is timestamped, so the path is handed on rather than being recalculated
        // later from the backup directory alone.
        $builder->setBackupArchivePath($this->createArchive->getArchivePath());

        return $builder;
    }
}
