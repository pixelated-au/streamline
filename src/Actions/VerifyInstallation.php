<?php

namespace Pixelated\Streamline\Actions;

use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Events\CommandClassCallback;

class VerifyInstallation
{
    public function __invoke(string $tempDir): void
    {
        $installed = (string) Config::get('streamline.installed_version');
        $expected  = getenv('STREAMLINE_EXPECTED_VERSION');

        if (getenv('STREAMLINE_UPDATE_COMPLETED') === '0') {
            CommandClassCallback::dispatch(
                'error',
                'The update did not complete' .
                ($installed !== '' ? ". The installed version is $installed." : '.')
            );

            return;
        }

        // FinishUpdate runs even when the pipeline aborted (e.g. a release failed verification), in
        // which case the previously installed version is still in place and must not be reported
        // as a success.
        if (is_string($expected) && $expected !== '' && ltrim($expected, 'vV') !== ltrim($installed, 'vV')) {
            CommandClassCallback::dispatch(
                'error',
                "The update to version $expected did not complete. The installed version is still $installed."
            );

            return;
        }

        CommandClassCallback::dispatch(
            'info',
            "Successfully installed version: $installed"
        );
    }
}
