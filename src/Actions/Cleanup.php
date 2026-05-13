<?php

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Events\CommandClassCallback;

class Cleanup
{
    public function __invoke(string $tempDir): void
    {
        CommandClassCallback::dispatch(
            'info',
            'Successfully installed version: ' . Config::get('streamline.installed_version')
        );
    }
}
