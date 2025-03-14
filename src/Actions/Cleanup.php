<?php

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Facades\File;
use Pixelated\Streamline\Events\CommandClassCallback;

class Cleanup
{
    public function __invoke(string $tempDir): void
    {
        CommandClassCallback::dispatch('comment', "Purging the temporary work directory: $tempDir");

        if (File::deleteDirectory($tempDir)) {
            CommandClassCallback::dispatch('info', "Temporary work directory purged successfully: $tempDir");
        } else {
            CommandClassCallback::dispatch('error', "Failed to purge temporary work directory: $tempDir");
        }
    }
}
