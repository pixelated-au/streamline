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
        $response->shouldReceive('header')->with('Content-Length')->andReturn('');

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

// Streamline verifies with a digest, which Ed25519 keys cannot use. Depending on the OpenSSL build that
// surfaces as an error or as a plain mismatch - and a mismatch would be misreported as tampering - so the
// key type is rejected before anything is verified. A fixed PEM keeps this independent of the PHP version.
it('refuses an Ed25519 public key rather than crying tamper', function() {
    Event::fake();

    Config::set('streamline.release_public_key', "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAr2KAEBqN13pZ5+k4dOVdDnQGoc8Lb9MYxZnRJ2JlYd4=\n-----END PUBLIC KEY-----\n");
    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => base64_encode('a signature that will never be checked'),
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'is not an RSA or EC key');

    expect(file_exists($this->archivePath))->toBeTrue();
});

it('aborts and deletes the archive when a signature over a different checksum is served', function() {
    Event::fake();
    $signedChecksum = hash('sha256', 'checksum A') . "  release.zip\n";

    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($this->privateKey, $signedChecksum),
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'signature is not valid');

    expect(file_exists($this->archivePath))->toBeFalse();
});

it('rejects a signature made over the archive bytes instead of the checksum file', function() {
    Event::fake();

    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($this->privateKey, SIGNED_ARCHIVE_CONTENTS),
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'signature is not valid');
});

it('rejects a sha256 signature when the configured algorithm is sha512', function() {
    Event::fake();
    Config::set('streamline.release_checksum_algorithm', 'sha512');
    $checksum = hash('sha512', SIGNED_ARCHIVE_CONTENTS) . "  release.zip\n";

    fakeReleaseAssets([
        'release.zip.sha512'     => $checksum,
        'release.zip.sha512.sig' => signWith($this->privateKey, $checksum),
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'signature is not valid');
});

it('verifies with an RSA key', function() {
    Event::fake();
    $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    Config::set('streamline.release_public_key', openssl_pkey_get_details($rsa)['key']);

    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($rsa, $this->checksum),
    ]);

    $builder = signatureBuilder($this->archivePath);

    expect((new VerifyReleaseSignature)($builder))->toBe($builder);
});

it('verifies with a file:// key path', function() {
    Event::fake();
    $path = tempnam(sys_get_temp_dir(), 'pub');
    file_put_contents($path, $this->publicKey);
    Config::set('streamline.release_public_key', "file://$path");

    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($this->privateKey, $this->checksum),
    ]);

    $builder = signatureBuilder($this->archivePath);

    try {
        expect((new VerifyReleaseSignature)($builder))->toBe($builder);
    } finally {
        unlink($path);
    }
});

it('treats an empty signature file as missing', function() {
    Event::fake();
    fakeReleaseAssets([CHECKSUM_FILE => $this->checksum, SIGNATURE_FILE => '']);

    $builder = signatureBuilder($this->archivePath);

    expect((new VerifyReleaseSignature)($builder))->toBe($builder);

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'warn'
    );
});

it('does not describe a garbage signature as tampering', function(string $garbage) {
    Event::fake();
    fakeReleaseAssets([CHECKSUM_FILE => $this->checksum, SIGNATURE_FILE => $garbage]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class);
})->with(['non-base64 text' => ['!!! not a signature !!!'], 'short bytes' => ["\x01\x02\x03"]]);

it('aborts on an invalid signature even when signatures are not required', function() {
    Event::fake();
    Config::set('streamline.require_release_signature', false);
    [$otherKey] = releaseKeyPair();

    fakeReleaseAssets([
        CHECKSUM_FILE  => $this->checksum,
        SIGNATURE_FILE => signWith($otherKey, $this->checksum),
    ]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'signature is not valid');
});

it('warns when the signature is published but the checksum file is not', function() {
    Event::fake();
    fakeReleaseAssets([SIGNATURE_FILE => 'whatever']);

    $builder = signatureBuilder($this->archivePath);

    expect((new VerifyReleaseSignature)($builder))->toBe($builder);

    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'warn'
    );
});

// i15b: a private key pasted into the public key setting must not be used, or quietly leaked
// onto the deployment as if it were configuration.
it('rejects a private key supplied as the public key', function() {
    Event::fake();
    $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($rsa, $privatePem);
    Config::set('streamline.release_public_key', $privatePem);

    fakeReleaseAssets([CHECKSUM_FILE => $this->checksum, SIGNATURE_FILE => signWith($rsa, $this->checksum)]);

    expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
        ->toThrow(RuntimeException::class, 'is a PRIVATE key');
});

it('rejects a private key supplied as a file path', function() {
    Event::fake();
    $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($rsa, $privatePem);
    $path = tempnam(sys_get_temp_dir(), 'priv');
    file_put_contents($path, $privatePem);
    Config::set('streamline.release_public_key', $path);

    try {
        expect(fn() => (new VerifyReleaseSignature)(signatureBuilder($this->archivePath)))
            ->toThrow(RuntimeException::class, 'is a PRIVATE key');
    } finally {
        unlink($path);
    }
});

// i15a: a digest PHP hashes but OpenSSL does not know is an error, not a mismatch.
it('reports a digest OpenSSL does not support as an error rather than tampering', function() {
    Event::fake();
    Config::set('streamline.release_checksum_algorithm', 'crc32');
    $checksum = hash('crc32', SIGNED_ARCHIVE_CONTENTS) . "  release.zip\n";
    // 8 hex chars is below the digest parser's minimum, so the signed content is irrelevant:
    // the algorithm is rejected before anything is compared.
    fakeReleaseAssets(['release.zip.crc32' => $checksum, 'release.zip.crc32.sig' => 'sig']);

    $thrown = null;

    try {
        (new VerifyReleaseSignature)(signatureBuilder($this->archivePath));
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain("'crc32'")
        ->not->toContain('tampered')
        ->not->toContain('not valid for the configured public key');
});

it('does not call a verification error tampering when OpenSSL fails', function() {
    Event::fake();
    // sha3-256 is known to OpenSSL but a bogus signature yields -1 (error), not 0 (mismatch).
    fakeReleaseAssets([CHECKSUM_FILE => $this->checksum, SIGNATURE_FILE => "\x01\x02\x03"]);

    $thrown = null;

    try {
        (new VerifyReleaseSignature)(signatureBuilder($this->archivePath));
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown?->getMessage())->not->toContain('tampered');
});
