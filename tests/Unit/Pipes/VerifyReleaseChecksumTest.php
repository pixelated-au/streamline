<?php

use Illuminate\Http\Client\Response;
use org\bovigo\vfs\vfsStream;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Facades\GitHubApi;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\VerifyReleaseChecksum;

const ARCHIVE_CONTENTS = 'the contents of the downloaded release archive';

beforeEach(function() {
    $root          = vfsStream::setup('checksum_test');
    $this->archive = vfsStream::newFile('release.zip')->withContent(ARCHIVE_CONTENTS);
    $root->addChild($this->archive);

    $this->archivePath = $this->archive->url();
    $this->digest      = hash('sha256', ARCHIVE_CONTENTS);

    Config::set('streamline.release_archive_file_name', 'release.zip');
    Config::set('streamline.release_checksum_algorithm', 'sha256');
    Config::set('streamline.require_release_checksum', false);
});

function checksumBuilder(string $archivePath): UpdateBuilderInterface
{
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturnNull();
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v1.2.3');
    $builder->shouldReceive('getDownloadedArchivePath')->andReturn($archivePath);
    $builder->shouldReceive('getVerifiedChecksum')->andReturnNull();
    $builder->shouldReceive('setVerifiedChecksum')->andReturnSelf();

    return $builder;
}

function fakeChecksumResponse(?string $body): void
{
    GitHubApi::shouldReceive('withWebUrl')
        ->with('releases/download/v1.2.3/release.zip.sha256')
        ->andReturnSelf();
    // These must be reset by the pipe, otherwise the request would overwrite the archive it is
    // meant to be verifying.
    GitHubApi::shouldReceive('withDownloadPath')->with(null)->andReturnSelf();
    GitHubApi::shouldReceive('withProgressCallback')->with(null)->andReturnSelf();

    if ($body === null) {
        GitHubApi::shouldReceive('get')->andThrow(new RuntimeException('Error: Failed to connect to GitHub API'));

        return;
    }

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('body')->andReturn($body);
    $response->shouldReceive('header')->with('Content-Length')->andReturn('');
    GitHubApi::shouldReceive('get')->andReturn($response);
}

it('passes the builder through when the archive matches the published checksum', function() {
    Event::fake();
    fakeChecksumResponse($this->digest);

    $builder = checksumBuilder($this->archivePath);
    $result  = (new VerifyReleaseChecksum)($builder);

    expect($result)->toBe($builder)
        ->and(file_exists($this->archivePath))->toBeTrue();

    $expectedMessage = 'Verified the release archive against its sha256 checksum.';

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'info' && $event->value === $expectedMessage
    );
});

it('accepts the standard shasum output format', function() {
    Event::fake();
    fakeChecksumResponse("$this->digest  release.zip\n");

    $builder = checksumBuilder($this->archivePath);

    expect((new VerifyReleaseChecksum)($builder))->toBe($builder);
});

it('aborts the update and deletes the archive when the checksum does not match', function() {
    Event::fake();
    $wrongDigest = hash('sha256', 'a completely different archive');
    fakeChecksumResponse($wrongDigest);

    expect(fn() => (new VerifyReleaseChecksum)(checksumBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'does not match its published sha256 checksum');

    expect(file_exists($this->archivePath))->toBeFalse();
});

it('warns but continues when no checksum is published and one is not required', function() {
    Event::fake();
    fakeChecksumResponse(null);

    $builder = checksumBuilder($this->archivePath);

    expect((new VerifyReleaseChecksum)($builder))->toBe($builder)
        ->and(file_exists($this->archivePath))->toBeTrue();

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'warn'
            && str_contains($event->value, 'could not be verified')
    );
});

it('aborts the update when no checksum is published and one is required', function() {
    Event::fake();
    Config::set('streamline.require_release_checksum', true);
    fakeChecksumResponse(null);

    expect(fn() => (new VerifyReleaseChecksum)(checksumBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'does not publish a readable release.zip.sha256');
});

it('aborts the update when the checksum file cannot be parsed and one is required', function() {
    Event::fake();
    Config::set('streamline.require_release_checksum', true);
    fakeChecksumResponse('<html>404: Not Found</html>');

    expect(fn() => (new VerifyReleaseChecksum)(checksumBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'does not publish a readable release.zip.sha256');
});

it('does no work when a signed checksum has already verified the archive', function() {
    Event::fake();
    // No GitHubApi expectations at all: reaching the network here would mean the pipe had ignored
    // the stronger verification the signature pipe already performed.
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getVerifiedChecksum')->andReturn($this->digest);

    expect((new VerifyReleaseChecksum)($builder))->toBe($builder);

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'comment'
            && str_contains($event->value, 'already verified against a signed checksum')
    );
});
