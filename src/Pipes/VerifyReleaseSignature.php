<?php

namespace Pixelated\Streamline\Pipes;

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use Pixelated\Streamline\Pipes\Concerns\VerifiesReleaseAssets;
use RuntimeException;

/**
 * Proves the release is genuine, rather than merely intact.
 *
 * The trust anchor is a public key held by this deployment, so a release that was not signed with
 * the matching private key is refused no matter who published it - which is the difference between
 * this and VerifyReleaseChecksum, whose checksum comes from the release it is checking.
 *
 * What is signed is the *checksum file*, not the archive: openssl_verify() has no streaming form
 * and would need the whole archive resident in memory, which is not a safe assumption on the shared
 * hosting this package targets. Signing the checksum gives the same guarantee through a short
 * chain - the key vouches for the digest, and the digest vouches for the archive - while never
 * holding more than a few dozen bytes.
 */
class VerifyReleaseSignature implements Pipe
{
    use VerifiesReleaseAssets;

    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $publicKey = $this->resolvePublicKey();

        if ($publicKey === null) {
            $this->handleMissingPublicKey();

            return $builder;
        }

        $algorithm        = (string) config('streamline.release_checksum_algorithm', 'sha256');
        $checksumFileName = config('streamline.release_archive_file_name') . '.' . $algorithm;
        $signatureName    = $checksumFileName . '.' . ltrim((string) config('streamline.release_signature_file_extension', 'sig'), '.');
        $version          = $builder->getRequestedVersion() ?? $builder->getNextAvailableRepositoryVersion();

        CommandClassCallback::dispatch('comment', "Retrieving the release signature from releases/download/$version/$signatureName");

        $checksumBody = $this->fetchReleaseAsset($version, $checksumFileName);
        $signature    = $this->fetchReleaseAsset($version, $signatureName);

        if ($checksumBody === null || $signature === null) {
            $this->handleMissingSignature($signatureName, $checksumFileName);

            return $builder;
        }

        $this->assertSignatureMatches($builder->getDownloadedArchivePath(), $checksumBody, $signature, $publicKey, $algorithm);

        $digest = $this->parseChecksum($checksumBody);

        if ($digest === null) {
            throw new RuntimeException(
                "Error: The signature over $checksumFileName is valid, but the file does not contain a readable " .
                'digest. Publish either a bare digest or the standard `shasum` output format.'
            );
        }

        // The signature covers the digest, so verifying the archive against it completes the chain
        // here rather than leaving it to VerifyReleaseChecksum - a pipeline that omits that step
        // would otherwise have a valid signature and an unchecked archive.
        $this->assertChecksumMatches($builder->getDownloadedArchivePath(), $algorithm, $digest);

        $builder->setVerifiedChecksum($digest);

        CommandClassCallback::dispatch('info', 'Verified the release signature against the configured public key.');

        return $builder;
    }

    /**
     * The key may be given as the PEM itself or as a path to a PEM file. A PEM pasted into a .env
     * file usually arrives with literal "\n" sequences rather than real newlines, so those are
     * restored before OpenSSL sees it.
     */
    protected function resolvePublicKey(): ?string
    {
        $key = config('streamline.release_public_key');

        if (!is_string($key) || trim($key) === '') {
            return null;
        }

        $key = trim($key);

        if (str_contains($key, '-----BEGIN')) {
            return str_replace('\n', "\n", $key);
        }

        $path = str_starts_with($key, 'file://') ? substr($key, 7) : $key;

        if (!is_readable($path) || !is_file($path)) {
            throw new RuntimeException(
                "Error: The configured release public key '$key' is neither a PEM block nor a readable file. " .
                'Set STREAMLINE_RELEASE_PUBLIC_KEY to the path of your public key, or to the PEM itself.'
            );
        }

        return (string) file_get_contents($path);
    }

    protected function handleMissingPublicKey(): void
    {
        if (!config('streamline.require_release_signature')) {
            // Silent by design: WarnUnverifiedRelease has already said this at the top of the run,
            // and repeating it here would bury the message rather than reinforce it.
            return;
        }

        throw new RuntimeException(
            'Error: STREAMLINE_REQUIRE_RELEASE_SIGNATURE is on but no public key is configured, so the release ' .
            'cannot be verified. Set STREAMLINE_RELEASE_PUBLIC_KEY to your signing key, or turn the requirement off.'
        );
    }

    protected function handleMissingSignature(string $signatureName, string $checksumFileName): void
    {
        $message = "The release does not publish a readable $signatureName and $checksumFileName, so it could not be verified against the configured public key.";

        if (config('streamline.require_release_signature')) {
            throw new RuntimeException(
                "Error: $message Sign your releases, or set STREAMLINE_REQUIRE_RELEASE_SIGNATURE=false to allow " .
                'unsigned archives to be installed.'
            );
        }

        CommandClassCallback::dispatch(
            'warn',
            "Warning: $message Continuing without signature verification. Set " .
            'STREAMLINE_REQUIRE_RELEASE_SIGNATURE=true once your release process signs its checksum file.'
        );
    }

    protected function assertSignatureMatches(
        string $archivePath,
        string $signedContent,
        string $signature,
        string $publicKey,
        string $algorithm,
    ): void {
        if (!function_exists('openssl_verify')) {
            throw new RuntimeException(
                'Error: Signature verification needs the OpenSSL extension, which is not loaded. Enable ext-openssl ' .
                'or turn STREAMLINE_REQUIRE_RELEASE_SIGNATURE off.'
            );
        }

        $key = openssl_pkey_get_public($publicKey);

        if ($key === false) {
            throw new RuntimeException(
                'Error: The configured release public key could not be read by OpenSSL. It should be a PEM-encoded ' .
                'public key, beginning "-----BEGIN PUBLIC KEY-----".'
            );
        }

        $sawOpenSslError = false;

        foreach ($this->candidateSignatures($signature) as $candidate) {
            $result = openssl_verify($signedContent, $candidate, $key, $algorithm);

            if ($result === 1) {
                return;
            }

            // -1 is OpenSSL refusing to perform the check at all, which is a different problem from
            // a signature that simply does not match - most often an Ed25519 key, which PHP's
            // openssl_verify() cannot use however valid the signature is.
            $sawOpenSslError = $sawOpenSslError || $result === -1;
        }

        if ($sawOpenSslError) {
            throw new RuntimeException(
                "Error: OpenSSL could not check the release signature using '$algorithm'. If your signing key is " .
                'Ed25519, PHP cannot verify it - re-sign your releases with an RSA or EC (P-256) key. The archive ' .
                'has been left in place because it was not shown to be invalid.'
            );
        }

        $this->discardArchive($archivePath);

        throw new RuntimeException(
            'Error: The release signature is not valid for the configured public key. Either the release was not ' .
            'signed with the matching private key, or it has been tampered with. The downloaded archive has been ' .
            'deleted and the update has been aborted.'
        );
    }

    /**
     * `openssl dgst -sign` writes raw bytes, but a signature that has travelled through a text
     * pipeline is often base64. Both are tried rather than guessing from the content, because raw
     * signature bytes can themselves happen to be valid base64.
     *
     * @return string[]
     */
    protected function candidateSignatures(string $signature): array
    {
        $candidates = [$signature];
        $decoded    = base64_decode(trim($signature), true);

        if ($decoded !== false && $decoded !== '' && $decoded !== $signature) {
            $candidates[] = $decoded;
        }

        return $candidates;
    }
}
