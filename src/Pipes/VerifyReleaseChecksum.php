<?php

namespace Pixelated\Streamline\Pipes;

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use Pixelated\Streamline\Pipes\Concerns\VerifiesReleaseAssets;
use RuntimeException;

/**
 * Verifies the downloaded release archive against a checksum file published alongside it before
 * anything is unpacked over the live installation.
 *
 * NOTE: a checksum served from the same release only proves the download arrived intact. It does
 * not prove the release is genuine, because anyone able to replace the archive can replace the
 * checksum with it. Establishing authenticity is what VerifyReleaseSignature is for.
 */
class VerifyReleaseChecksum implements Pipe
{
    use VerifiesReleaseAssets;

    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        if ($builder->getVerifiedChecksum() !== null) {
            // VerifyReleaseSignature already checked the archive against a digest it could prove
            // came from the key holder, which is strictly stronger than repeating the work here.
            CommandClassCallback::dispatch('comment', 'The release archive was already verified against a signed checksum.');

            return $builder;
        }

        $algorithm        = (string) config('streamline.release_checksum_algorithm', 'sha256');
        $checksumFileName = config('streamline.release_archive_file_name') . '.' . $algorithm;
        $version          = $builder->getRequestedVersion() ?? $builder->getNextAvailableRepositoryVersion();

        CommandClassCallback::dispatch('comment', "Retrieving the release checksum from releases/download/$version/$checksumFileName");

        $body     = $this->fetchReleaseAsset($version, $checksumFileName);
        $expected = $body === null ? null : $this->parseChecksum($body);

        if (!$expected) {
            $this->handleMissingChecksum($checksumFileName);

            return $builder;
        }

        $this->assertChecksumMatches($builder->getDownloadedArchivePath(), $algorithm, $expected);

        $builder->setVerifiedChecksum($expected);

        CommandClassCallback::dispatch('info', "Verified the release archive against its $algorithm checksum.");

        return $builder;
    }

    protected function handleMissingChecksum(string $checksumFileName): void
    {
        $message = "The release does not publish a readable $checksumFileName, so the downloaded archive could not be verified.";

        if (config('streamline.require_release_checksum')) {
            throw new RuntimeException(
                "Error: $message Publish a checksum file with your release, or set " .
                'STREAMLINE_REQUIRE_RELEASE_CHECKSUM=false to allow unverified archives to be installed.'
            );
        }

        CommandClassCallback::dispatch(
            'warn',
            "Warning: $message Continuing without verification. Set STREAMLINE_REQUIRE_RELEASE_CHECKSUM=true to " .
            'refuse unverified archives once your release process publishes one.'
        );
    }
}
