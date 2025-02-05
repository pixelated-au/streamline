<?php

use Pixelated\Streamline\Facades\CleanUpAssets;

it('should delete without setting the number of revisions using a "Y" prompt', function () {
    CleanUpAssets::shouldReceive('run')->with(null);

    $this->artisan('streamline:clean-assets')
        ->expectsConfirmation('Are you sure you want to the assets directory?', 'yes')
        ->expectsOutputToContain('Cleaning up the front-end build assets directory')
        ->doesntExpectOutputToContain('Retaining')
        ->assertExitCode(0);
});

it('should not delete without setting the number of revisions using a "N" prompt', function () {
    CleanUpAssets::shouldReceive('run')->with(null);

    $this->artisan('streamline:clean-assets')
        ->expectsConfirmation('Are you sure you want to the assets directory?')
        ->expectsOutputToContain('Cleaning aborted.')
        ->doesntExpectOutputToContain('Cleaning up the front-end build assets directory')
        ->assertExitCode(1);
});

it('should delete without setting the number of revisions (using force) and not receive a prompt to continue', function () {
    CleanUpAssets::shouldReceive('run')->with(null);

    $this->artisan('streamline:clean-assets --force')
        ->expectsOutputToContain('Cleaning up the front-end build assets directory')
        ->assertExitCode(0);
});

it('should delete by setting the number of revisions using a "Y" prompt', function () {
    CleanUpAssets::shouldReceive('run')->with(5);

    $this->artisan('streamline:clean-assets --revisions=5')
        ->expectsConfirmation('Are you sure you want to the assets directory?', 'yes')
        ->expectsOutputToContain('Cleaning up the front-end build assets directory')
        ->expectsOutputToContain('Retaining 5 revisions')
        ->assertExitCode(0);
});

it('should not delete by setting the number of revisions using a "N" prompt', function () {
    CleanUpAssets::shouldReceive('run')->with(null);

    $this->artisan('streamline:clean-assets --revisions=5')
        ->expectsConfirmation('Are you sure you want to the assets directory?')
        ->expectsOutputToContain('Cleaning aborted.')
        ->doesntExpectOutputToContain('Cleaning up the front-end build assets directory')
        ->doesntExpectOutputToContain('Retaining 5 revisions')
        ->assertExitCode(1);
});

it('should delete by setting the number of revisions (using force) and not receive a prompt to continue', function () {
    CleanUpAssets::shouldReceive('run')->with(3);

    $this->artisan('streamline:clean-assets --revisions=3 --force')
        ->expectsOutputToContain('Cleaning up the front-end build assets directory')
        ->expectsOutputToContain('Retaining 3 revisions')
        ->assertExitCode(0);
});

it('ignores non-integer value for revisions', function () {
    CleanUpAssets::shouldReceive('run')->with(3);
    $this->artisan('streamline:clean-assets --revisions=invalid')
        ->expectsOutputToContain('Invalid number of revisions. Please provide a positive integer.')
        ->assertExitCode(1);
});
