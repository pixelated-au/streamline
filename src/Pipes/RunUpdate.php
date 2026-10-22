<?php

namespace Pixelated\Streamline\Pipes;

use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;

readonly class RunUpdate implements Pipe
{
    public function __construct(private InstantiateStreamlineUpdater $runUpdate) {}

    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $this->runUpdate->execute(
            versionToInstall: $builder->getRequestedVersion() ?? $builder->getNextAvailableRepositoryVersion(),
            composerPath: $builder->getComposerPath(),
            backupArchivePath: $builder->getBackupArchivePath(),
            callback: function(string $type, string $output) {
                // Never throw from here: that would stop the updater part-way through swapping
                // releases. stderr is shown as a warning; the process exit code decides success.
                if ($type === 'err') {
                    CommandClassCallback::dispatch('warn', $output);

                    return;
                }

                CommandClassCallback::dispatch('line', $output);
            }
        );

        // Only reached if the updater process exited successfully; a failure throws above.
        $builder->markUpdateCompleted();

        return $builder;
    }
}
