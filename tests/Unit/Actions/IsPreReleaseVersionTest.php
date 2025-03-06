<?php

use Pixelated\Streamline\Actions\IsPreReleaseVersion;

it('should return false when version does not end with any of the suffix markers', function() {
    $action = new IsPreReleaseVersion;

    expect($action->execute('1.0.0'))->toBeFalse()
        ->and($action->execute('2.0'))->toBeFalse()
        ->and($action->execute('1.5.2-rc1'))->toBeFalse()
        ->and($action->execute('3.0.0-dev'))->toBeFalse();
});

it('should return true when version ends with a suffix marker', function() {
    $action = new IsPreReleaseVersion;

    expect($action->execute('1.0.0a'))->toBeTrue()
        ->and($action->execute('2.0b'))->toBeTrue()
        ->and($action->execute('1.5.2alpha'))->toBeTrue()
        ->and($action->execute('3.0.0beta'))->toBeTrue()
        ->and($action->execute('1.5.2-alpha'))->toBeTrue()
        ->and($action->execute('3.0.0-beta'))->toBeTrue();
});

it('should handle case sensitivity', function() {
    $action = new IsPreReleaseVersion;

    expect($action->execute('1.0.0Alpha'))->toBeFalse()
        ->and($action->execute('2.0Beta'))->toBeFalse()
        ->and($action->execute('1.5.2ALPHA'))->toBeFalse()
        ->and($action->execute('3.0.0BETA'))->toBeFalse()
        ->and($action->execute('1.0.0-Alpha'))->toBeFalse()
        ->and($action->execute('2.0-Beta'))->toBeFalse();
});

it('should correctly process strings where suffix is part of another word', function() {
    $action = new IsPreReleaseVersion;

    expect($action->execute('alphabetical'))->toBeFalse()
        ->and($action->execute('1.0.0alphabet'))->toBeFalse()
        ->and($action->execute('2.0betamax'))->toBeFalse()
        ->and($action->execute('1.5.2-alphabet'))->toBeFalse()
        ->and($action->execute('3.0.0-betaversion'))->toBeFalse();
});
