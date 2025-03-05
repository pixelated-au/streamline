<?php

use org\bovigo\vfs\vfsStream;

beforeAll(fn() => putenv('IS_TESTING=' . StreamlineUpdater::TESTING_ON));

it('will fail when it is missing environment variables', function() {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/Environment variable .* needs to be set!/');
    $envVars = collect(getEnvVars())
        ->map(fn($value) => $value === '')
        ->toArray();
    setEnv($envVars);
    new StreamlineUpdater;
});

it('can find the composer autoload file in the default vendor directory', function() {
    symlink(realpath('./composer.json'), './workbench/temp/composer.json');

    setEnv(['LARAVEL_BASE_PATH' => './workbench/temp']);
    $updater = new StreamlineUpdater;
    expect($updater->autoloadFile())->toBe('vendor/autoload.php');
    unlink('./workbench/temp/composer.json');
});

it('can find the composer autoload file in a different directory', function() {
    $projectPath = 'path/to/project/vendor-directory';

    $root = vfsStream::setup('streamline');
    $root->addChild(vfsStream::newDirectory($projectPath));

    /** @var \org\bovigo\vfs\vfsStreamDirectory $project */
    $project = $root->getChild($projectPath);

    $vendor = vfsStream::newDirectory('vendor-directory');
    $root->addChild($vendor);

    $composerFile = vfsStream::newFile('composer.json')->withContent(
        collect(['config' => ['vendor-dir' => $vendor->url()]])->toJson(JSON_THROW_ON_ERROR)
    );
    $project->addChild($composerFile);

    setEnv(['LARAVEL_BASE_PATH' => $project->url(), 'IS_TESTING' => StreamlineUpdater::TESTING_SKIP_REQUIRE_AUTOLOAD]);
    $updater = new StreamlineUpdater;

    expect($updater->autoloadFile())->toBe($vendor->url() . '/autoload.php');
});

it('throws an error when it cannot find composer.json file', function() {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Cannot locate the base composer file (./not-working/composer.json)');
    setEnv(['LARAVEL_BASE_PATH' => './not-working']);
    (new StreamlineUpdater)->autoloadFile();
});

it('throws an error when the composer.json file is invalid', function() {
    $root = vfsStream::setup('streamline');

    $composerFile = vfsStream::newFile('composer.json')->withContent('[broken json');
    $root->addChild($composerFile);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('The file ' . $root->url() . '/composer.json file contains invalid JSON');

    setEnv(['LARAVEL_BASE_PATH' => $root->url(), 'IS_TESTING' => StreamlineUpdater::TESTING_SKIP_REQUIRE_AUTOLOAD]);
    (new StreamlineUpdater)->autoloadFile();
});

it('can initialise the RunUpdate class', function() {
    setEnv();
    (new StreamlineUpdater)->run();
})->throwsNoExceptions();

it('should add an error message to envIssues when JSON parsing fails twice', function() {
    setEnv(
        ['PROTECTED_PATHS' => 'invalid json']
    );
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/Environment variable PROTECTED_PATHS=invalid json cannot be converted to an array. Pass in a JSON compatible array string!/');
    (new StreamlineUpdater)->run();
});

/**
 * @param array{
 *     TEMP_DIR?: string,
 *     LARAVEL_BASE_PATH?: string,
 *     PUBLIC_DIR_NAME?: string,
 *     FRONT_END_BUILD_DIR?: string,
 *     INSTALLING_VERSION?: string,
 *     PROTECTED_PATHS?: array<string>,
 *     DIR_PERMISSION?: string,
 *     FILE_PERMISSION?: string,
 *     OLD_RELEASE_ARCHIVE_PATH?: string,
 *     DO_RETAIN_OLD_RELEASE?: string,
 *     IS_TESTING?: int,
 * } $overrides
 */
function setEnv(array $overrides = []): void
{
    if (isset($overrides['IS_TESTING'])) {
        $overrides['IS_TESTING'] = StreamlineUpdater::TESTING_ON + $overrides['IS_TESTING'];
    }
    $envVars = array_merge(getEnvVars(), $overrides);

    foreach ($envVars as $key => $value) {
        putenv("$key=$value");
    }
}

function getEnvVars(): array
{
    return [
        'TEMP_DIR'                 => '/path/to/temp',
        'LARAVEL_BASE_PATH'        => './workbench',
        'PUBLIC_DIR_NAME'          => 'public',
        'FRONT_END_BUILD_DIR'      => 'build',
        'INSTALLING_VERSION'       => '1.0.0',
        'PROTECTED_PATHS'          => '["protected.txt"]',
        'DIR_PERMISSION'           => '0755',
        'FILE_PERMISSION'          => '0644',
        'OLD_RELEASE_ARCHIVE_PATH' => '/path/to/old/release.zip',
        'DO_RETAIN_OLD_RELEASE'    => 'true',
        'IS_TESTING'               => StreamlineUpdater::TESTING_ON,
    ];
}
