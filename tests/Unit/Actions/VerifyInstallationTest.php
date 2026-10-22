<?php

use Pixelated\Streamline\Actions\VerifyInstallation;
use Pixelated\Streamline\Events\CommandClassCallback;

afterEach(function() {
    putenv('STREAMLINE_EXPECTED_VERSION');
    putenv('STREAMLINE_UPDATE_COMPLETED');
});

it('reports success when the installed version is the one that was meant to be installed', function() {
    Event::fake();
    Config::set('streamline.installed_version', 'v2.0.0');
    putenv('STREAMLINE_EXPECTED_VERSION=v2.0.0');

    (new VerifyInstallation)('/tmp');

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $e) => $e->action === 'info' && $e->value === 'Successfully installed version: v2.0.0'
    );
});

it('does not report success when the update was aborted and the old version is still installed', function() {
    Event::fake();
    Config::set('streamline.installed_version', 'v1.0.0');
    putenv('STREAMLINE_EXPECTED_VERSION=v2.0.0');

    (new VerifyInstallation)('/tmp');

    Event::assertNotDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $e) => str_contains($e->value, 'Successfully installed')
    );
    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $e) => $e->action === 'error'
            && str_contains($e->value, 'v2.0.0')
            && str_contains($e->value, 'v1.0.0')
    );
});

it('ignores a leading v when comparing versions', function() {
    Event::fake();
    Config::set('streamline.installed_version', '2.0.0');
    putenv('STREAMLINE_EXPECTED_VERSION=v2.0.0');

    (new VerifyInstallation)('/tmp');

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $e) => $e->action === 'info'
    );
});

it('does not report success for a same-version forced reinstall that aborted', function() {
    Event::fake();
    Config::set('streamline.installed_version', 'v2.0.0');
    putenv('STREAMLINE_EXPECTED_VERSION=v2.0.0');
    putenv('STREAMLINE_UPDATE_COMPLETED=0');

    (new VerifyInstallation)('/tmp');

    Event::assertNotDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $e) => str_contains($e->value, 'Successfully installed')
    );
    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $e) => $e->action === 'error' && str_contains($e->value, 'did not complete')
    );
});

it('reports success for a completed same-version forced reinstall', function() {
    Event::fake();
    Config::set('streamline.installed_version', 'v2.0.0');
    putenv('STREAMLINE_EXPECTED_VERSION=v2.0.0');
    putenv('STREAMLINE_UPDATE_COMPLETED=1');

    (new VerifyInstallation)('/tmp');

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $e) => $e->action === 'info' && str_contains($e->value, 'Successfully installed')
    );
});

it('still catches a version mismatch when the update is flagged as completed', function() {
    Event::fake();
    Config::set('streamline.installed_version', 'v1.0.0');
    putenv('STREAMLINE_EXPECTED_VERSION=v2.0.0');
    putenv('STREAMLINE_UPDATE_COMPLETED=1');

    (new VerifyInstallation)('/tmp');

    Event::assertDispatched(CommandClassCallback::class, fn(CommandClassCallback $e) => $e->action === 'error');
});
