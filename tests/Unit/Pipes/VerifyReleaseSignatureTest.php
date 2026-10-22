<?php

use Illuminate\Http\Client\Response;
use org\bovigo\vfs\vfsStream;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Facades\GitHubApi;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\VerifyReleaseSignature;

const SIGNED_ARCHIVE_CONTENTS = 'the contents of the signed release archive';

const CHECKSUM_FILE = 'release.zip.sha256';

const SIGNATURE_FILE = 'release.zip.sha256.sig';

/**
 * A throwaway EC key pair. EC and RSA are the only families PHP's openssl_verify() can handle, and
 * EC is generated fast enough to do per-test.
 *
 * @return array{0: OpenSSLAsymmetricKey, 1: string}
 */
function releaseKeyPair(): array
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);

    return [$key, openssl_pkey_get_details($key)['key']];
}

function signWith(OpenSSLAsymmetricKey $key, string $content): string
{
    openssl_sign($content, $signature, $key, 'sha256');

    return $signature;
}

/**
 * Serves the named files from the release, and behaves like a 404 for anything else.
 *
 * @param  array<string, string>  $files  keyed by file name, not by full path
 */
function fakeReleaseAssets(array $files): void
{
    $requested = new stdClass;

    GitHubApi::shouldReceive('withWebUrl')->andReturnUsing(function(string $path) use ($requested) {
        $requested->file = basename($path);

        return GitHubApi::getFacadeRoot();
    });
    // Must be cleared by the pipe or the request would overwrite the archive being verified.
    GitHubApi::shouldReceive('withDownloadPath')->with(null)->andReturnSelf();
    GitHubApi::shouldReceive('withProgressCallback')->with(null)->andReturnSelf();
    GitHubApi::shouldReceive('get')->andReturnUsing(function() use ($requested, $files) {
        if (!array_key_exists($requested->file, $files)) {
            throw new RuntimeException('Error: Failed to connect to GitHub API');
        }

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('body')->andReturn($files[$requested->file]);

        return $response;
    });
}

function signatureBuilder(string $archivePath): UpdateBuilderInterface
{
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getRequestedVersion')->andReturnNull();
    $builder->shouldReceive('getNextAvailableRepositoryVersion')->andReturn('v1.2.3');
    $builder->shouldReceive('getDownloadedArchivePath')->andReturn($archivePath);
    $builder->shouldReceive('setVerifiedChecksum')->andReturnSelf()->byDefault();

    return $builder;
}

beforeEach(function() {
    $this->root = vfsStream::setup('signature_test');
    $this->root->addChild(vfsStream::newFile('release.zip')->withContent(SIGNED_ARCHIVE_CONTENTS));

    $this->archivePath = vfsStream::url('signature_test/release.zip');
    $this->digest      = hash('sha256', SIGNED_ARCHIVE_CONTENTS);
    $this->checksum    = "$this->digest  release.zip\n";

    [$this->privateKey, $this->publicKey] = releaseKeyPair();

    Config::set('streamline.release_archive_file_name', 'release.zip');
    Config::set('streamline.release_checksum_algorithm', 'sha256');
    Config::set('streamline.release_signature_file_extension', 'sig');
    Config::set('streamline.require_release_signature', false);
    Config::set('streamline.release_public_key', $this->publicKey);
});

it('verifies a signed checksum and records the digest on the builder', function() {
    Event::fake();
    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($this->privateKey, $this->checksum),
    ]);

    $builder = signatureBuilder($this->archivePath);
    $builder->shouldReceive('setVerifiedChecksum')->once()->with($this->digest)->andReturnSelf();

    expect((new VerifyReleaseSignature)($builder))->toBe($builder)
        ->and(file_exists($this->archivePath))->toBeTrue();

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'info'
            && str_contains($event->value, 'Verified the release signature')
    );
});

it('accepts a base64 encoded signature', function() {
    Event::fake();
    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => base64_encode(signWith($this->privateKey, $this->checksum)) . "\n",
    ]);

    $builder = signatureBuilder($this->archivePath);

    expect((new VerifyReleaseSignature)($builder))->toBe($builder);
});

it('reads the public key from a file when given a path', function() {
    Event::fake();
    vfsStream::newFile('release.pub')
        ->withContent($this->publicKey)
        ->at($this->root);
    Config::set('streamline.release_public_key', vfsStream::url('signature_test/release.pub'));

    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($this->privateKey, $this->checksum),
    ]);

    $builder = signatureBuilder($this->archivePath);

    expect((new VerifyReleaseSignature)($builder))->toBe($builder);
});

// A PEM pasted into a .env file arrives with literal backslash-n rather than real newlines.
it('restores escaped newlines in a key given inline', function() {
    Event::fake();
    Config::set('streamline.release_public_key', str_replace("\n", '\n', $this->publicKey));

    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($this->privateKey, $this->checksum),
    ]);

    $builder = signatureBuilder($this->archivePath);

    expect((new VerifyReleaseSignature)($builder))->toBe($builder);
});

it('aborts and deletes the archive when the signature was made by a different key', function() {
    Event::fake();
    [$otherKey] = releaseKeyPair();

    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($otherKey, $this->checksum),
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'signature is not valid for the configured public key');

    expect(file_exists($this->archivePath))->toBeFalse();
});

it('aborts and deletes the archive when the signed checksum does not match it', function() {
    Event::fake();
    $wrongChecksum = hash('sha256', 'a completely different archive') . "  release.zip\n";

    fakeReleaseAssets([
        CHECKSUM_FILE  => $wrongChecksum,
        SIGNATURE_FILE => signWith($this->privateKey, $wrongChecksum),
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'does not match its published sha256 checksum');

    expect(file_exists($this->archivePath))->toBeFalse();
});

it('aborts when a validly signed checksum file holds no digest', function() {
    Event::fake();
    $body = "nothing resembling a digest\n";

    fakeReleaseAssets([
        CHECKSUM_FILE  => $body,
        SIGNATURE_FILE => signWith($this->privateKey, $body),
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'does not contain a readable digest');
});

it('does nothing when no public key is configured and signatures are not required', function() {
    Event::fake();
    Config::set('streamline.release_public_key', null);

    // No GitHubApi expectations: without a key there is nothing to check against, and the banner
    // has already said so.
    $builder = signatureBuilder($this->archivePath);

    expect((new VerifyReleaseSignature)($builder))->toBe($builder);

    Event::assertNotDispatched(CommandClassCallback::class);
});

it('aborts when signatures are required but no public key is configured', function() {
    Event::fake();
    Config::set('streamline.release_public_key', '');
    Config::set('streamline.require_release_signature', true);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'no public key is configured');
});

it('aborts when the configured key is neither a PEM nor a readable file', function() {
    Event::fake();
    Config::set('streamline.release_public_key', vfsStream::url('signature_test/absent.pub'));

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'neither a PEM block nor a readable file');
});

it('aborts when OpenSSL cannot read the configured key', function() {
    Event::fake();
    Config::set('streamline.release_public_key', "-----BEGIN PUBLIC KEY-----\nnot actually a key\n-----END PUBLIC KEY-----");

    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($this->privateKey, $this->checksum),
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'could not be read by OpenSSL');
});

it('warns but continues when the release publishes no signature', function() {
    Event::fake();
    fakeReleaseAssets([CHECKSUM_FILE => $this->checksum]);

    $builder = signatureBuilder($this->archivePath);

    expect((new VerifyReleaseSignature)($builder))->toBe($builder)
        ->and(file_exists($this->archivePath))->toBeTrue();

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'warn'
            && str_contains($event->value, 'could not be verified against the configured public key')
    );
});

it('aborts when the release publishes no signature and one is required', function() {
    Event::fake();
    Config::set('streamline.require_release_signature', true);
    fakeReleaseAssets([CHECKSUM_FILE => $this->checksum]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'does not publish a readable release.zip.sha256.sig');

    // Nothing was disproved, so the download is left alone.
    expect(file_exists($this->archivePath))->toBeTrue();
});

// PHP's openssl_verify() cannot use Ed25519 keys at all - it reports an error rather than a
// mismatch, and saying "tampered with" there would send the operator hunting for an attacker.
it('explains itself rather than crying tamper when OpenSSL refuses the key type', function() {
    Event::fake();
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);

    if ($key === false) {
        $this->markTestSkipped('This OpenSSL build cannot generate Ed25519 keys.');
    }

    Config::set('streamline.release_public_key', openssl_pkey_get_details($key)['key']);
    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => 'a signature this build of PHP will not even attempt to check',
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 're-sign your releases with an RSA or EC (P-256) key');

    expect(file_exists($this->archivePath))->toBeTrue();
});
