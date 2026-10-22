<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Testing\PendingCommand;
use Pixelated\Streamline\Tests\LaravelTestCase;
use Pixelated\Streamline\Tests\TestCase;
use Pixelated\Streamline\Tests\Unit\Traits\Utils;

$_ENV['TEST_DIR'] = __DIR__;

/**
 * Links workbench/vendor at the package's own vendor directory.
 *
 * testbench.yaml points Laravel's base path at ./workbench, so a test that boots the real updater
 * resolves the autoloader to ./workbench/vendor/autoload.php and requires it for real. Testbench
 * creates this link itself, but only while running its own console commands - never during a test
 * run - so without this a fresh clone fails until someone runs `composer build`.
 *
 * The link is deliberately not committed: it holds an absolute path, and so is only ever correct on
 * the machine that made it.
 */
(static function(): void {
    $packageVendor   = dirname(__DIR__) . '/vendor';
    $workbenchVendor = dirname(__DIR__) . '/workbench/vendor';

    if (is_file($workbenchVendor . '/autoload.php')) {
        return;
    }

    // A link made on another machine points nowhere. file_exists() reports false for a broken
    // symlink while the name itself is still taken, so it has to be cleared before relinking.
    if (is_link($workbenchVendor)) {
        unlink($workbenchVendor);
    }

    if (is_dir($packageVendor) && !file_exists($workbenchVendor)) {
        // Filesystem::link() rather than symlink(), so this also works on Windows.
        (new Filesystem)->link($packageVendor, $workbenchVendor);
    }
})();

/**
 * @see PendingCommand
 * @see LaravelTestCase Some setup is done in here
 */
pest()
    ->extends(LaravelTestCase::class)
    ->beforeEach(fn() => Mockery::close())
    ->afterEach(fn() => Mockery::close())
    ->group('feature', 'core')
    ->in('Feature');

pest()
    ->extends(LaravelTestCase::class)
    ->afterEach(fn() => Mockery::close())
    ->use(Utils::class)
    ->group('feature', 'core')
    ->in('Updater/Feature');

pest()
    ->extends(LaravelTestCase::class)
    ->afterEach(fn() => Mockery::close())
    ->group('unit', 'core')
    ->in('Unit');

pest()
    ->extends(TestCase::class)
    ->afterEach(function() {
        Mockery::close();
        $this->cleanUp(); // Utils::cleanUp()
    })
    ->use(Utils::class)
    ->group('unit', 'core')
    ->in('Updater/Unit');

pest()
    ->extends(LaravelTestCase::class)
    ->afterEach(fn() => Mockery::close())
    ->group('arch')
    ->in('Arch');
