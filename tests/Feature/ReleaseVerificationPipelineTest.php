<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Pixelated\Streamline\Factories\ProcessFactory;
use Pixelated\Streamline\Pipes\DownloadRelease;
use Pixelated\Streamline\Pipes\MakeTempDir;
use Pixelated\Streamline\Pipes\UnpackRelease;
use Pixelated\Streamline\Pipes\VerifyReleaseChecksum;
use Pixelated\Streamline\Pipes\VerifyReleaseSignature;
use Pixelated\Streamline\Pipes\WarnUnverifiedRelease;
use Pixelated\Streamline\Services\FinishUpdate;
use Symfony\Component\Process\Process;

/**
 * End to end through `streamline:run-update`: real UpdateBuilder, real GitHubApi, Http faked per
 * release asset URL. The pipeline stops after UnpackRelease so the live site is never touched, and
 * the updater process factory must never be asked for a process.
 */
const RELEASE_URL = 'https://github.com/acme/site/releases/download/v1.2.3/';

beforeEach(function() {
    $this->workDir = sys_get_temp_dir() . '/streamline_verify_' . bin2hex(random_bytes(4));

    // A real release archive containing one file.
    $zipPath = tempnam(sys_get_temp_dir(), 'rel');
    $zip     = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('app.txt', 'hello from the release');
    $zip->close();
    $this->archive = file_get_contents($zipPath);
    unlink($zipPath);

    $this->digest   = hash('sha256', $this->archive);
    $this->checksum = "$this->digest  release.zip\n";

    $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_sign($this->checksum, $this->signature, $this->key, 'sha256');

    Config::set('streamline.github_repo', 'acme/site');
    Config::set('streamline.github_auth_token', null);
    Config::set('streamline.work_temp_dir', $this->workDir);
    Config::set('streamline.release_archive_file_name', 'release.zip');
    Config::set('streamline.release_checksum_algorithm', 'sha256');
    Config::set('streamline.release_signature_file_extension', 'sig');
    Config::set('streamline.require_release_checksum', false);
    Config::set('streamline.require_release_signature', false);
    Config::set('streamline.release_public_key', openssl_pkey_get_details($this->key)['key']);
    Config::set('streamline.installed_version', 'v1.0.0');
    Config::set('streamline.pipeline-update', [
        WarnUnverifiedRelease::class,
        MakeTempDir::class,
        DownloadRelease::class,
        VerifyReleaseSignature::class,
        VerifyReleaseChecksum::class,
        UnpackRelease::class,
    ]);

    // Anything reaching the updater would mean verification did not stop the update.
    $this->mock(ProcessFactory::class, fn(MockInterface $mock) => $mock->shouldReceive('invoke')->never());

    $this->finishEnv = 'unset';
    $this->app->bind(Process::class, function($app, array $params) {
        $this->finishEnv = $params['env'] ?? null;
        $mock            = Mockery::mock(Process::class);
        $mock->shouldReceive('run')->andReturn(0);
        $mock->shouldReceive('isSuccessful')->andReturnTrue();
        $mock->shouldReceive('getOutput')->andReturn('finish output');

        return $mock;
    });

    $this->fakeRelease = function(array $overrides = []) {
        $files = array_merge([
            'release.zip'            => $this->archive,
            'release.zip.sha256'     => $this->checksum,
            'release.zip.sha256.sig' => $this->signature,
        ], $overrides);

        Http::fake(function(Request $request) use ($files) {
            $name = basename(parse_url($request->url(), PHP_URL_PATH));

            return isset($files[$name]) && $files[$name] !== null
                ? Http::response($files[$name])
                : Http::response('', 404);
        });
    };
});

afterEach(fn() => File::deleteDirectory($this->workDir));

it('verifies, then unpacks a correctly signed and checksummed release', function() {
    Config::set('streamline.require_release_checksum', true);
    Config::set('streamline.require_release_signature', true);
    ($this->fakeRelease)();

    $this->artisan('streamline:run-update --install-version=v1.2.3')
        ->expectsOutputToContain('Verified the release signature')
        ->assertExitCode(0);

    expect(file_get_contents("$this->workDir/app.txt"))->toBe('hello from the release');

    // The checksum/signature fetches must neither overwrite the archive (it unpacked intact) nor
    // inherit stale pagination parameters from an earlier request.
    Http::assertSent(fn(Request $r) => str_ends_with($r->url(), 'release.zip.sha256'));
    Http::assertNotSent(fn(Request $r) => str_contains($r->url(), 'page='));
});

it('aborts a tampered archive, deletes it and never unpacks', function() {
    Config::set('streamline.require_release_checksum', true);
    Config::set('streamline.require_release_signature', true);
    ($this->fakeRelease)(['release.zip' => $this->archive . 'tampered']);

    $this->artisan('streamline:run-update --install-version=v1.2.3')
        ->expectsOutputToContain('does not match its published sha256 checksum')
        ->assertExitCode(1);

    expect(file_exists("$this->workDir/release.zip"))->toBeFalse()
        ->and(file_exists("$this->workDir/app.txt"))->toBeFalse();
});

it('aborts when a checksum is required and the release publishes none', function() {
    Config::set('streamline.require_release_checksum', true);
    Config::set('streamline.release_public_key', null);
    ($this->fakeRelease)(['release.zip.sha256' => null]);

    $this->artisan('streamline:run-update --install-version=v1.2.3')
        ->expectsOutputToContain('does not publish a readable release.zip.sha256')
        ->assertExitCode(1);

    expect(file_exists("$this->workDir/app.txt"))->toBeFalse();
});

it('warns and carries on when verification is not enforced and no checksum is published', function() {
    Config::set('streamline.release_public_key', null);
    ($this->fakeRelease)(['release.zip.sha256' => null]);

    $this->artisan('streamline:run-update --install-version=v1.2.3')
        ->expectsOutputToContain('Continuing without verification')
        ->assertExitCode(0);

    expect(file_exists("$this->workDir/app.txt"))->toBeTrue();
});

it('aborts before downloading anything when a required check has been removed from the pipeline', function() {
    Config::set('streamline.require_release_checksum', true);
    Config::set('streamline.pipeline-update', [
        WarnUnverifiedRelease::class,
        MakeTempDir::class,
        DownloadRelease::class,
        VerifyReleaseSignature::class,
        UnpackRelease::class,
    ]);
    ($this->fakeRelease)();

    $this->artisan('streamline:run-update --install-version=v1.2.3')
        ->expectsOutputToContain('VerifyReleaseChecksum')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

// i6: the finish step still runs after an abort, so it has to be told what should have been
// installed, otherwise it reports the old version as a fresh success.
it('tells the finish step which version was being installed, even after an abort', function() {
    Config::set('streamline.require_release_checksum', true);
    ($this->fakeRelease)(['release.zip' => $this->archive . 'tampered']);
    Config::set('streamline.pipeline-finish-class', FinishUpdate::class);

    $this->artisan('streamline:run-update --install-version=v1.2.3')->assertExitCode(1);

    expect($this->finishEnv)->toBe([
        'STREAMLINE_EXPECTED_VERSION' => 'v1.2.3',
        'STREAMLINE_UPDATE_COMPLETED' => '0',
    ]);
});

// The guard in WarnUnverifiedRelease disappears if that pipe is itself removed, so the command
// validates the pipeline before it starts.
it('fails before any request when WarnUnverifiedRelease is removed and a checksum is required', function() {
    Config::set('streamline.require_release_checksum', true);
    Config::set('streamline.pipeline-update', [
        MakeTempDir::class,
        DownloadRelease::class,
        VerifyReleaseSignature::class,
        UnpackRelease::class,
    ]);
    ($this->fakeRelease)();

    $this->artisan('streamline:run-update --install-version=v1.2.3')
        ->expectsOutputToContain('VerifyReleaseChecksum')
        ->assertExitCode(1);

    Http::assertNothingSent();
    expect(file_exists("$this->workDir/release.zip"))->toBeFalse();
});

it('fails before any request when WarnUnverifiedRelease is removed and a signature is required', function() {
    Config::set('streamline.require_release_signature', true);
    Config::set('streamline.pipeline-update', [
        MakeTempDir::class,
        DownloadRelease::class,
        VerifyReleaseChecksum::class,
        UnpackRelease::class,
    ]);
    ($this->fakeRelease)();

    $this->artisan('streamline:run-update --install-version=v1.2.3')
        ->expectsOutputToContain('VerifyReleaseSignature')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
