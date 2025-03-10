<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Factories\ProcessFactory;
use Pixelated\Streamline\Tests\Feature\Traits\HttpMock;
use Pixelated\Streamline\Tests\Feature\Traits\UpdateCommandCommon;

pest()->uses(UpdateCommandCommon::class, HttpMock::class);

beforeEach(function() {
    $this->app->bind(
        \Symfony\Component\Process\Process::class,
        function() {
            // For some reason, $this->mock(...) isn't working as expected, so I'm mocking it manually
            $mock = Mockery::mock(\Symfony\Component\Process\Process::class);
            $mock->shouldReceive('run')
                ->andReturn(0);
            $mock->shouldReceive('isSuccessful')
                ->andReturnTrue();
            $mock->shouldReceive('getOutput')
                ->andReturn('Test success message');

            return $mock;
        }
    );

    $process = Mockery::mock(Symfony\Component\Process\Process::class);

    $process->shouldReceive('__construct')
        ->andReturnSelf();
    $process->shouldReceive('setEnv')
        ->andReturnSelf();
    $process->shouldReceive('run');

    $this->mock(
        ProcessFactory::class,
        function(MockInterface $mock) use ($process) {
            $mock->shouldReceive('__construct')
                ->andReturnSelf();
            $mock->shouldReceive('invoke')
                ->andReturn($process);
        }
    );
    //    Config::set('streamline.external_process_class', get_class($process));
});

it('should run an update with no parameters', function() {
    Process::preventStrayProcesses();

    $this->mockFile()
        ->mockCache(['v2.8.1', 'v2.0.0', 'v1.0.0'], 'v2.8.1')
        ->mockZipArchive();

    $this->mockGetWebArchive();

    Config::set('streamline.laravel_build_dir_name', '/path/to/build');

    File::shouldReceive('exists')->andReturnTrue();
    File::shouldReceive('isWritable')->andReturnTrue();
    File::shouldReceive('isReadable')->andReturnTrue();
    File::shouldReceive('deleteDirectory');

    Event::fake(CommandClassCallback::class);
    $this->app->bind(
        \Symfony\Component\Process\Process::class,
        function() {
            // For some reason, $this->mock(...) isn't working as expected, so I'm mocking it manually
            $mock = Mockery::mock(\Symfony\Component\Process\Process::class);
            $mock->shouldReceive('run')
                ->andReturn(0);
            $mock->shouldReceive('isSuccessful')
                ->andReturnTrue();
            $mock->shouldReceive('getOutput')
                ->andReturn('test output');

            return $mock;
        }
    );

    //    Process::fake([
    //        '* artisan streamline:finish-update' => Process::result('test output'),
    //    ]);

    $this->artisan('streamline:run-update')
        ->assertExitCode(0);

    //    Process::assertRan(PHP_BINARY . ' artisan streamline:finish-update');

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'info' && Str::startsWith($event->value, 'test output')
    );
});

it('should run an update with a specific version', function() {
    $this->mockFile()
        ->mockCache(['v2.9.0', 'v2.8.1', 'v2.0.0', 'v1.0.0'], 'v2.0.0')
        ->mockGetAvailableVersions()
        ->mockZipArchive();
    File::shouldReceive('deleteDirectory');
    Config::set('streamline.installed_version', 'v2.8.1');
    $this->mockGetWebArchive();

    $this->artisan('streamline:run-update --install-version=v2.9.0')
        ->expectsOutputToContain('Changing deployment to version: v2.9.0')
        ->assertExitCode(0);
});

it('should run an update but notifies there are no new versions', function() {
    $this->mockCache(['v2.0.0', 'v1.0.0'], 'v2.0.0');
    $this->mockGetWebArchive();
    Config::set('streamline.installed_version', 'v2.0.0');

    $this->artisan('streamline:run-update')
        ->expectsOutputToContain('You are currently using the latest version (v2.0.0)')
        ->assertExitCode(0);
});

it('should run an update but cannot find any available versions and return an error', function() {
    $this->mockCache([]);
    $this->mockGetWebArchive()
        ->mockHttpReleases(Http::response([]));

    $this->artisan('streamline:run-update')
        ->expectsOutputToContain('The next available version could not be determined.')
        ->assertExitCode(1);
});

it('should run an update but GitHub returns a connection error', function() {
    $this->mockCache(['v2.0.0', 'v1.0.0'], 'v2.0.0');
    Config::set('streamline.installed_version', 'v1.0.0');
    Http::fake(['github.com/*' => Http::failedConnection()]);

    $this->artisan('streamline:run-update')
        ->expectsOutputToContain('Failed to connect to GitHub API')
        ->assertExitCode(1);
});

it(
    'should run an update requesting a version but it is older than the installed version and then throw an error',
    function() {
        $this->mockCache(['v2.0.0', 'v1.0.0'])
            ->mockGetAvailableVersions();
        $this->mockHttpReleases();

        Config::set('streamline.installed_version', 'v2.0.0');

        $this->artisan('streamline:run-update --install-version=v1.0.0')
            ->expectsOutputToContain('Version v1.0.0 is not greater than the current version (v2.0.0)')
            ->assertExitCode(1);
    }
);

it('should run an update requesting a pre-release version and fail', function(string $version) {
    $this->mockCache(availableVersions: [$version, 'v1.0.0']);
    $this->mockHttpReleases();

    $this->artisan("streamline:run-update --install-version=$version")
        ->expectsOutputToContain("Version $version is a pre-release version, use --force to install it.")
        ->assertExitCode(1);
})->with(['v1.0.1-alpha', 'v1.0.1-beta', 'v1.0.1a', 'v1.0.1b']);

it('should run an update requesting a pre-release version with --force and succeed', function(string $version) {
    Process::preventStrayProcesses();

    $this->mockFile()
        ->mockCache(availableVersions: [$version, 'v1.0.0'])
        ->mockGetAvailableVersions()
        ->mockZipArchive();

    File::shouldReceive('deleteDirectory');

    Http::preventStrayRequests();
    Http::fake(['github.com/*' => Http::response([])]);

    $this->app->bind(
        \Symfony\Component\Process\Process::class,
        function() {
            // For some reason, $this->mock(...) isn't working as expected, so I'm mocking it manually
            $mock = Mockery::mock(\Symfony\Component\Process\Process::class);
            $mock->shouldReceive('run')
                ->andReturn(0);
            $mock->shouldReceive('isSuccessful')
                ->andReturnTrue();
            $mock->shouldReceive('getOutput')
                ->andReturn('test output');

            return $mock;
        }
    );
    //    Process::fake([
    //        '* artisan streamline:finish-update' => Process::result('test output'),
    //    ]);

    //    $this->withoutMockingConsoleOutput();
    //    $this->artisan("streamline:run-update --install-version=$version --force");
    $this->artisan("streamline:run-update --install-version=$version --force")
        ->expectsOutputToContain("Version: $version will be installed")
        ->expectsOutputToContain("Downloading archive for version $version")
        ->assertExitCode(0);
    //        Process::assertRan(PHP_BINARY . ' artisan streamline:finish-update');
})->with(['v1.0.1-alpha', 'v1.0.1-beta', 'v1.0.1a', 'v1.0.1b']);

it('should run an update requesting an invalid version and return an error', function() {
    $this->mockCache();
    $this->mockHttpReleases();

    $this->artisan('streamline:run-update --install-version=hello')
        ->expectsOutputToContain('Version hello is not a valid version!')
        ->assertExitCode(1);
});

it('should run a "forced" update requesting an invalid version and return an error', function() {
    $this->mockCache();
    $this->mockHttpReleases();

    $this->artisan('streamline:run-update --install-version=hello --force')
        ->expectsOutputToContain('Version hello is not a valid version!')
        ->assertExitCode(1);
});

it('should run a "forced" update on an existing version', function() {
    $this->mockFile()
        ->mockCache(['v2.0.0', 'v1.0.0'])
        ->mockGetAvailableVersions()
        ->mockZipArchive();
    File::shouldReceive('deleteDirectory');
    Config::set('streamline.installed_version', 'v2.0.0');

    $this->mockGetWebArchive();
    $this->artisan('streamline:run-update --force --install-version=v1.0.0')
        ->expectsOutputToContain('Version v1.0.0 is not greater than the current version (v2.0.0) (Forced update)')
        ->assertExitCode(0);
});

it('should run a "forced" update on the most recent', function() {
    $this->mockFile()
        ->mockCache(['v2.0.0', 'v1.0.0'], 'v2.0.0')
        ->mockGetAvailableVersions()
        ->mockZipArchive();
    File::shouldReceive('deleteDirectory');

    Config::set('streamline.installed_version', 'v2.0.0');

    $this->mockGetWebArchive();
    $this->artisan('streamline:run-update --force')
        ->expectsOutputToContain('You are currently using the latest version (v2.0.0) (Forced update)')
        ->assertExitCode(0);
});
