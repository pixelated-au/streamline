<?php

use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Actions\VerifyInstallation;
use Pixelated\Streamline\Events\CommandClassCallback;

it('should dispatch proper events', function() {
    $tempDir = '/path/to/temp/dir';

    Event::fake();

    $cleanup = new VerifyInstallation;
    $cleanup($tempDir);

    Event::assertDispatched(
        fn(CommandClassCallback $event) => $event->action === 'info' && 'Successfully installed version: 5.4.3'
    );
});

it('should dispatch an failed event when it could not delete the temp dir', function() {
    $tempDir = '/path/to/temp/dir';

    Event::fake();

    $cleanup = new VerifyInstallation;
    $cleanup($tempDir);

    Config::set('streamline.installed_version', '5.4.3');

    Event::assertDispatched(
        fn(CommandClassCallback $event) => $event->action === 'info' && 'Successfully installed version: 5.4.3'
    );
});
