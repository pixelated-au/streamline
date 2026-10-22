<?php

use Illuminate\Http\Client\Response;
use org\bovigo\vfs\vfsStream;
use Pixelated\Streamline\Facades\GitHubApi;
use Pixelated\Streamline\Pipes\Concerns\VerifiesReleaseAssets;

/**
 * Exposes the trait's protected methods so they can be exercised directly.
 */
function assetVerifier(): object
{
    return new class
    {
        use VerifiesReleaseAssets;

        public function fetch(string $version, string $fileName): ?string
        {
            return $this->fetchReleaseAsset($version, $fileName);
        }

        public function parse(string $body): ?string
        {
            return $this->parseChecksum($body);
        }

        public function assertMatches(string $archivePath, string $algorithm, string $expected): void
        {
            $this->assertChecksumMatches($archivePath, $algorithm, $expected);
        }
    };
}

beforeEach(function() {
    $this->root = vfsStream::setup('assets_test');
    $this->root->addChild(vfsStream::newFile('release.zip')->withContent('archive bytes'));

    $this->archivePath = vfsStream::url('assets_test/release.zip');
    $this->digest      = hash('sha256', 'archive bytes');
});

it('throws and keeps the archive when the algorithm is not supported by PHP', function() {
    expect(fn() => assetVerifier()->assertMatches($this->archivePath, 'nope', $this->digest))
        ->toThrow(RuntimeException::class, "'nope' is not a hashing algorithm supported");

    expect(file_exists($this->archivePath))->toBeTrue();
});

it('throws and keeps the archive when the archive cannot be read', function() {
    $missing = vfsStream::url('assets_test/missing.zip');

    expect(fn() => assetVerifier()->assertMatches($missing, 'sha256', $this->digest))
        ->toThrow(RuntimeException::class, 'Cannot read the downloaded archive');
});

it('accepts an upper case digest once parsed', function() {
    $expected = assetVerifier()->parse(strtoupper($this->digest));

    expect($expected)->toBe($this->digest);

    assetVerifier()->assertMatches($this->archivePath, 'sha256', $expected);

    expect(file_exists($this->archivePath))->toBeTrue();
});

it('deletes the archive when a digest of a different length is published', function(string $digest) {
    expect(fn() => assetVerifier()->assertMatches($this->archivePath, 'sha256', $digest))
        ->toThrow(RuntimeException::class, 'does not match its published sha256 checksum');

    expect(file_exists($this->archivePath))->toBeFalse();
})->with([
    'md5 length'    => fn() => md5('archive bytes'),
    'sha512 digest' => fn() => hash('sha512', 'archive bytes'),
]);

it('parses the supported checksum formats', function(string $body, ?string $expected) {
    expect(assetVerifier()->parse($body))->toBe($expected);
})->with(function() {
    $digest = hash('sha256', 'archive bytes');

    return [
        'bare digest'            => [$digest, $digest],
        'shasum output'          => ["$digest  release.zip\n", $digest],
        'BSD style'              => ["SHA256 (release.zip) = $digest\n", $digest],
        'upper case'             => [strtoupper($digest), $digest],
        'html error page'        => ['<html>404: Not Found</html>', null],
        'a run of 129 hex chars' => [str_repeat('a', 129), null],
        'too short'              => [str_repeat('a', 31), null],
    ];
});

// Documents the fail-closed behaviour: the first digest wins whichever file it belongs to, so a
// SHA256SUMS listing another file first makes the archive mismatch and the update abort, rather
// than the wrong digest being silently accepted.
it('uses the first digest in a multi-entry file and so fails closed when it belongs to another file', function() {
    $other = hash('sha256', 'some other file');
    $body  = "$other  other.tar.gz\n$this->digest  release.zip\n";

    expect(assetVerifier()->parse($body))->toBe($other);

    expect(fn() => assetVerifier()->assertMatches($this->archivePath, 'sha256', $other))
        ->toThrow(RuntimeException::class, 'does not match');
});

function fakeAssetBody(?string $body, string $contentLength = ''): void
{
    GitHubApi::shouldReceive('withWebUrl')->andReturnSelf();
    GitHubApi::shouldReceive('withDownloadPath')->with(null)->andReturnSelf();
    GitHubApi::shouldReceive('withProgressCallback')->with(null)->andReturnSelf();
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('body')->andReturn($body ?? '');
    $response->shouldReceive('header')->with('Content-Length')->andReturn($contentLength);
    GitHubApi::shouldReceive('get')->andReturn($response);
}

it('treats an empty body as a missing asset', function() {
    fakeAssetBody('');

    expect(assetVerifier()->fetch('v1.2.3', 'release.zip.sha256'))->toBeNull();
});

it('returns the body of a small asset', function() {
    fakeAssetBody('abc');

    expect(assetVerifier()->fetch('v1.2.3', 'release.zip.sha256'))->toBe('abc');
});

it('fails clearly when an asset body is implausibly large', function() {
    fakeAssetBody(str_repeat('a', 1024 * 1024));

    expect(fn() => assetVerifier()->fetch('v1.2.3', 'release.zip.sha256'))
        ->toThrow(RuntimeException::class, 'larger than the 65536 byte limit');
});

it('fails clearly when the declared Content-Length is implausibly large', function() {
    fakeAssetBody('abc', '999999999');

    expect(fn() => assetVerifier()->fetch('v1.2.3', 'release.zip.sha256'))
        ->toThrow(RuntimeException::class, 'larger than the 65536 byte limit');
});

// Digest comparisons must stay timing-safe. A grep over the source is crude, but it is the guard
// against someone "simplifying" hash_equals() into ===.
it('compares digests with hash_equals and never with an equality operator', function() {
    $source = file_get_contents(
        dirname(__DIR__, 4) . '/src/Pipes/Concerns/VerifiesReleaseAssets.php'
    );
    $pipes = [
        'VerifyReleaseChecksum.php',
        'VerifyReleaseSignature.php',
    ];

    expect($source)->toContain('hash_equals(');

    foreach ([$source, ...array_map(fn($f) => file_get_contents(dirname(__DIR__, 4) . "/src/Pipes/$f"), $pipes)] as $code) {
        expect(preg_match('/\$(expected|actual|digest)\w*\s*(?>===|!==|==|!=)\s*(?!null\b)\S/i', $code))
            ->toBe(0);
    }
});
