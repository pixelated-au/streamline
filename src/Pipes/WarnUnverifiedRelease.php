<?php

namespace Pixelated\Streamline\Pipes;

use Pixelated\Streamline\Actions\ValidateVerificationPipeline;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;

/**
 * Announces, before anything is downloaded, that this deployment is about to install a release it
 * cannot verify.
 *
 * Both integrity settings ship turned off so that existing installations keep updating after an
 * upgrade of this package. That is a defensible default only if it is impossible to miss, hence
 * the banner rather than a line of warning text in the middle of the update output.
 */
class WarnUnverifiedRelease implements Pipe
{
    public const string DOCUMENTATION_URL = 'https://github.com/pixelated-au/streamline/blob/main/docs/verifying-releases.md';

    /** Inner width of the banner, excluding the border characters on either side. */
    private const int WIDTH = 74;

    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        (new ValidateVerificationPipeline)();

        foreach ($this->bannerLines() as $line) {
            CommandClassCallback::dispatch('warn', $line);
        }

        return $builder;
    }

    /**
     * Returns an empty array when both checks are enforced, which is what silences the banner.
     *
     * @return string[]
     */
    public function bannerLines(): array
    {
        $validator = new ValidateVerificationPipeline;

        // A flag only counts if the pipe that honours it will actually run before anything is unpacked.
        $checksumOff  = !config('streamline.require_release_checksum') || !$validator->isPipedBeforeUnpack(VerifyReleaseChecksum::class);
        $signatureOff = !config('streamline.require_release_signature')
            || !config('streamline.release_public_key')
            || !$validator->isPipedBeforeUnpack(VerifyReleaseSignature::class);

        if (!$checksumOff && !$signatureOff) {
            return [];
        }

        $body = [
            '',
            '  !!  RELEASE VERIFICATION IS DISABLED  !!',
            '',
            '  This update will install whatever the release publishes. Streamline',
            '  cannot tell you whether it is genuine.',
            '',
            '  Not enforced:',
        ];

        if ($checksumOff) {
            $body[] = '    - Checksum   STREAMLINE_REQUIRE_RELEASE_CHECKSUM=true';
        }

        if ($signatureOff) {
            $body[] = '    - Signature  STREAMLINE_REQUIRE_RELEASE_SIGNATURE=true';
        }

        $body[] = '';

        return [
            '',
            '╔' . str_repeat('═', self::WIDTH) . '╗',
            ...array_map(
                // Padded to a fixed width so the right-hand border lines up, and so the styling
                // the console applies covers the whole block rather than ragged text.
                static fn(string $line): string => '║' . str_pad($line, self::WIDTH) . '║',
                $body
            ),
            '╚' . str_repeat('═', self::WIDTH) . '╝',
            '',
            // Deliberately outside the box: the URL is longer than the box is wide, and a border
            // sitting at the end of it makes the link awkward to select in a terminal.
            '  How to turn these on:',
            '  ' . self::DOCUMENTATION_URL,
            '',
        ];
    }
}
