<?php

use Pixelated\Streamline\Actions\IsPreReleaseVersion;

it('should return false when version does not end with any of the suffix markers', function() {
    $action = new IsPreReleaseVersion;

    expect($action->execute('1.0.0'))->toBeFalse()
        ->and($action->execute('2.0'))->toBeFalse()
        ->and($action->execute('v1.2.3'))->toBeFalse()
        ->and($action->execute('1.0.0+build-5'))->toBeFalse();
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

it('treats any SemVer pre-release suffix as a pre-release', function(string $version) {
    expect((new IsPreReleaseVersion)->execute($version))->toBeTrue();
})->with([
    '1.5.2-rc1', 'v1.5.2-RC.1', '1.0.0-RC', '1.0.0-alpha.1', '1.0.0-beta.2', '3.0.0-dev', '3.0.0-preview', '1.0.0-0.3.7', '1.0.0-rc1+build.5',
]);

it('should handle case sensitivity', function() {
    $action = new IsPreReleaseVersion;

    expect($action->execute('1.0.0Alpha'))->toBeFalse()
        ->and($action->execute('2.0Beta'))->toBeFalse()
        ->and($action->execute('1.5.2ALPHA'))->toBeFalse()
        ->and($action->execute('3.0.0BETA'))->toBeFalse()
        ->and($action->execute('1.0.0-Alpha'))->toBeTrue()
        ->and($action->execute('2.0-Beta'))->toBeTrue();
});

it('should correctly process strings where suffix is part of another word', function() {
    $action = new IsPreReleaseVersion;

    expect($action->execute('alphabetical'))->toBeFalse()
        ->and($action->execute('1.0.0alphabet'))->toBeFalse()
        ->and($action->execute('2.0betamax'))->toBeFalse()
        ->and($action->execute('1.5.2-alphabet'))->toBeTrue()
        ->and($action->execute('3.0.0-betaversion'))->toBeTrue();
});
