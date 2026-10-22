<?php

namespace Pixelated\Streamline\Pipes\Concerns;

use Pixelated\Streamline\Facades\GitHubApi;
use RuntimeException;
use Throwable;

/**
 * Shared plumbing for the two integrity pipes: pulling a small file out of the release next to the
 * archive, and checking the archive against a digest.
 */
trait VerifiesReleaseAssets
{
    /** Upper bound for a checksum or signature file. */
    protected const int MAX_ASSET_BYTES = 65536;

    /**
     * Fetches a small file published alongside the archive in the same release. Returns null when
     * it is absent or unreadable, which the caller reports according to its own enforcement flag.
     */
    protected function fetchReleaseAsset(string $version, string $fileName): ?string
    {
        $path = "releases/download/$version/$fileName";

        try {
            $response = GitHubApi::withWebUrl($path)
                // The facade caches its instance, so the download path and progress bar left over
                // from DownloadRelease must be cleared or this request would overwrite the archive
                // it is supposed to be verifying.
                ->withDownloadPath(null)
                ->withProgressCallback(null)
                ->get();
            $body     = $response->body();
            $declared = (int) $response->header('Content-Length');
        } catch (Throwable) {
            return null;
        }

        // Checksum and signature files are a few hundred bytes. The HTTP client has already
        // buffered the body by now, so this cannot prevent the download, but it stops an oversized
        // response from being parsed or handed to OpenSSL, and says why the update stopped.
        if ($declared > self::MAX_ASSET_BYTES || strlen($body) > self::MAX_ASSET_BYTES) {
            throw new RuntimeException(
                "Error: $fileName is larger than the " . self::MAX_ASSET_BYTES . ' byte limit for a checksum or ' .
                'signature file, so it was not used. Check the release for a wrong or malicious asset.'
            );
        }

        return $body === '' ? null : $body;
    }

    /**
     * Accepts either a bare digest or the standard `shasum` output format: "<digest>  <filename>".
     */
    protected function parseChecksum(string $body): ?string
    {
        if (preg_match('/\b([a-f0-9]{32,128})\b/i', $body, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    protected function assertChecksumMatches(string $archivePath, string $algorithm, string $expected): void
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new RuntimeException("Error: '$algorithm' is not a hashing algorithm supported by this PHP installation.");
        }

        if (!is_readable($archivePath)) {
            throw new RuntimeException("Error: Cannot read the downloaded archive to verify it: $archivePath");
        }

        $actual = hash_file($algorithm, $archivePath);

        if (!hash_equals($expected, $actual)) {
            // Do not leave an archive that failed verification where a later run could unpack it.
            $this->discardArchive($archivePath);

            throw new RuntimeException(
                "Error: The downloaded release archive does not match its published $algorithm checksum. " .
                "Expected '$expected' but calculated '$actual'. The download was either corrupted or has been " .
                'tampered with. The archive has been deleted and the update has been aborted.'
            );
        }
    }

    protected function discardArchive(string $archivePath): void
    {
        @unlink($archivePath);
    }
}
