<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\DownloadRelease;
use Pixelated\Streamline\Pipes\UnpackRelease;
use Pixelated\Streamline\Pipes\VerifyReleaseChecksum;
use Pixelated\Streamline\Pipes\VerifyReleaseSignature;
use Pixelated\Streamline\Pipes\WarnUnverifiedRelease;

function bannerText(): string
{
    return implode("\n", (new WarnUnverifiedRelease)->bannerLines());
}

beforeEach(function() {
    Config::set('streamline.require_release_checksum', true);
    Config::set('streamline.require_release_signature', true);
    Config::set('streamline.release_public_key', '-----BEGIN PUBLIC KEY-----');
});

it('says nothing when both checks are enforced', function() {
    Event::fake();

    $builder = Mockery::mock(UpdateBuilderInterface::class);

    expect((new WarnUnverifiedRelease)($builder))->toBe($builder)
        ->and((new WarnUnverifiedRelease)->bannerLines())->toBeEmpty();

    Event::assertNotDispatched(CommandClassCallback::class);
});

it('warns when the checksum is not enforced', function() {
    Config::set('streamline.require_release_checksum', false);

    expect(bannerText())
        ->toContain('RELEASE VERIFICATION IS DISABLED')
        ->toContain('STREAMLINE_REQUIRE_RELEASE_CHECKSUM=true')
        ->not->toContain('STREAMLINE_REQUIRE_RELEASE_SIGNATURE=true');
});

it('warns when the signature is not enforced', function() {
    Config::set('streamline.require_release_signature', false);

    expect(bannerText())
        ->toContain('STREAMLINE_REQUIRE_RELEASE_SIGNATURE=true')
        ->not->toContain('STREAMLINE_REQUIRE_RELEASE_CHECKSUM=true');
});

// Enforcement without a key would abort every update, so it is treated as "off" here rather than
// letting the banner claim a protection that is not actually in place.
it('warns when signatures are enforced but no public key is configured', function() {
    Config::set('streamline.release_public_key', null);

    expect(bannerText())->toContain('STREAMLINE_REQUIRE_RELEASE_SIGNATURE=true');
});

it('points at the documentation', function() {
    Config::set('streamline.require_release_checksum', false);

    expect(bannerText())->toContain(WarnUnverifiedRelease::DOCUMENTATION_URL);
});

it('draws a box whose lines are all the same width', function() {
    Config::set('streamline.require_release_checksum', false);
    Config::set('streamline.require_release_signature', false);

    $drawn = array_values(array_filter(
        (new WarnUnverifiedRelease)->bannerLines(),
        static fn(string $line) => preg_match('/^[╔║╚]/u', $line) === 1
    ));
    $widths = array_unique(array_map(mb_strlen(...), $drawn));

    expect($widths)->toHaveCount(1)
        ->and($drawn[0])->toStartWith('╔')
        ->and(end($drawn))->toStartWith('╚');
});

it('dispatches every line of the banner as a warning', function() {
    Event::fake();
    Config::set('streamline.require_release_checksum', false);

    $builder = Mockery::mock(UpdateBuilderInterface::class);

    expect((new WarnUnverifiedRelease)($builder))->toBe($builder);

    Event::assertDispatchedTimes(CommandClassCallback::class, count((new WarnUnverifiedRelease)->bannerLines()));
    Event::assertDispatched(
        CommandClassCallback::class,
        fn(CommandClassCallback $event) => $event->action === 'warn'
            && str_contains($event->value, 'RELEASE VERIFICATION IS DISABLED')
    );
});

// i13: the banner and the abort must reflect what the pipeline will actually do, not just the flags.
function setPipeline(array $pipes): void
{
    Config::set('streamline.pipeline-update', $pipes);
}

it('aborts before download when checksums are required but the pipe is missing', function() {
    Event::fake();
    setPipeline([
        WarnUnverifiedRelease::class,
        DownloadRelease::class,
        VerifyReleaseSignature::class,
        UnpackRelease::class,
    ]);

    expect(fn() => (new WarnUnverifiedRelease)(Mockery::mock(UpdateBuilderInterface::class)))
        ->toThrow(RuntimeException::class, 'VerifyReleaseChecksum');
});

it('aborts before download when signatures are required but the pipe is missing', function() {
    Event::fake();
    setPipeline([
        WarnUnverifiedRelease::class,
        DownloadRelease::class,
        VerifyReleaseChecksum::class,
        UnpackRelease::class,
    ]);

    expect(fn() => (new WarnUnverifiedRelease)(Mockery::mock(UpdateBuilderInterface::class)))
        ->toThrow(RuntimeException::class, 'VerifyReleaseSignature');
});

it('aborts when a required pipe is ordered after UnpackRelease', function() {
    Event::fake();
    setPipeline([
        WarnUnverifiedRelease::class,
        DownloadRelease::class,
        VerifyReleaseSignature::class,
        UnpackRelease::class,
        VerifyReleaseChecksum::class,
    ]);

    expect(fn() => (new WarnUnverifiedRelease)(Mockery::mock(UpdateBuilderInterface::class)))
        ->toThrow(RuntimeException::class, 'before UnpackRelease');
});

it('stays silent when both pipes are present and ordered before UnpackRelease', function() {
    Event::fake();
    setPipeline([
        WarnUnverifiedRelease::class,
        DownloadRelease::class,
        VerifyReleaseSignature::class,
        VerifyReleaseChecksum::class,
        UnpackRelease::class,
    ]);

    expect((new WarnUnverifiedRelease)->bannerLines())->toBeEmpty();
});

it('lists a check as not enforced when it is not required and its pipe is missing', function() {
    Config::set('streamline.require_release_checksum', false);
    setPipeline([WarnUnverifiedRelease::class, VerifyReleaseSignature::class, UnpackRelease::class]);

    expect(bannerText())
        ->toContain('STREAMLINE_REQUIRE_RELEASE_CHECKSUM=true')
        ->not->toContain('STREAMLINE_REQUIRE_RELEASE_SIGNATURE=true');
});

it('lists a check as not enforced when its pipe is after UnpackRelease', function() {
    Config::set('streamline.require_release_signature', false);
    setPipeline([WarnUnverifiedRelease::class, UnpackRelease::class, VerifyReleaseSignature::class, VerifyReleaseChecksum::class]);

    expect(bannerText())
        ->toContain('STREAMLINE_REQUIRE_RELEASE_SIGNATURE=true')
        ->toContain('STREAMLINE_REQUIRE_RELEASE_CHECKSUM=true');
});
