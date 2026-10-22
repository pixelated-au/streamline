<?php

use Pixelated\Streamline\Actions\ValidateVerificationPipeline;
use Pixelated\Streamline\Pipes\DownloadRelease;
use Pixelated\Streamline\Pipes\UnpackRelease;
use Pixelated\Streamline\Pipes\VerifyReleaseChecksum;
use Pixelated\Streamline\Pipes\VerifyReleaseSignature;

beforeEach(function() {
    Config::set('streamline.require_release_checksum', true);
    Config::set('streamline.require_release_signature', true);
});

it('passes when both required pipes run before UnpackRelease', function() {
    Config::set('streamline.pipeline-update', [
        DownloadRelease::class,
        VerifyReleaseSignature::class,
        VerifyReleaseChecksum::class,
        UnpackRelease::class,
    ]);

    (new ValidateVerificationPipeline)();

    expect(true)->toBeTrue();
});

it('throws when a required checksum pipe is missing', function() {
    Config::set('streamline.pipeline-update', [DownloadRelease::class, VerifyReleaseSignature::class, UnpackRelease::class]);

    expect(fn() => (new ValidateVerificationPipeline)())
        ->toThrow(RuntimeException::class, 'VerifyReleaseChecksum');
});

it('throws when a required signature pipe is ordered after UnpackRelease', function() {
    Config::set('streamline.pipeline-update', [
        VerifyReleaseChecksum::class,
        UnpackRelease::class,
        VerifyReleaseSignature::class,
    ]);

    expect(fn() => (new ValidateVerificationPipeline)())
        ->toThrow(RuntimeException::class, 'before UnpackRelease');
});

it('ignores pipes whose requirement is off', function() {
    Config::set('streamline.require_release_checksum', false);
    Config::set('streamline.require_release_signature', false);
    Config::set('streamline.pipeline-update', [UnpackRelease::class]);

    (new ValidateVerificationPipeline)();

    expect((new ValidateVerificationPipeline)->isPipedBeforeUnpack(VerifyReleaseChecksum::class))->toBeFalse();
});
