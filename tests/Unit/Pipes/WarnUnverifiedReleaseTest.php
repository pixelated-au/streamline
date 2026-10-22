<?php

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
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
