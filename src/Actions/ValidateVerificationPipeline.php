<?php

namespace Pixelated\Streamline\Actions;

use Pixelated\Streamline\Pipeline\Pipe;
use Pixelated\Streamline\Pipes\UnpackRelease;
use Pixelated\Streamline\Pipes\VerifyReleaseChecksum;
use Pixelated\Streamline\Pipes\VerifyReleaseSignature;
use RuntimeException;

/**
 * Enforcement flags are only honoured by their pipes, so a required check whose pipe has been
 * removed (or ordered after UnpackRelease) would silently verify nothing. Run by the update command
 * before the pipeline starts, and by WarnUnverifiedRelease, so removing either does not drop the guard.
 */
class ValidateVerificationPipeline
{
    /**
     * @throws RuntimeException
     */
    public function __invoke(): void
    {
        $checks = [
            'STREAMLINE_REQUIRE_RELEASE_CHECKSUM'  => ['require_release_checksum', VerifyReleaseChecksum::class],
            'STREAMLINE_REQUIRE_RELEASE_SIGNATURE' => ['require_release_signature', VerifyReleaseSignature::class],
        ];

        foreach ($checks as $envName => [$configKey, $pipe]) {
            if (config("streamline.$configKey") && !$this->isPipedBeforeUnpack($pipe)) {
                $name = class_basename($pipe);

                throw new RuntimeException(
                    "Error: $envName is on, but $name is not in the 'pipeline-update' config or does not run " .
                    'before UnpackRelease, so the release would not be verified. Add it to the pipeline ' .
                    'before UnpackRelease, or turn the requirement off.'
                );
            }
        }
    }

    /**
     * @param  class-string<Pipe>  $pipe
     */
    public function isPipedBeforeUnpack(string $pipe): bool
    {
        $position = null;
        $unpack   = null;

        foreach (array_values((array) config('streamline.pipeline-update', [])) as $index => $entry) {
            $class = is_object($entry) ? $entry::class : $entry;

            if (!is_string($class)) {
                continue;
            }

            if ($position === null && is_a($class, $pipe, true)) {
                $position = $index;
            }

            if ($unpack === null && is_a($class, UnpackRelease::class, true)) {
                $unpack = $index;
            }
        }

        return $position !== null && ($unpack === null || $position < $unpack);
    }
}
