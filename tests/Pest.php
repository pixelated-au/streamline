<?php

use Pixelated\Streamline\Tests\LaravelTestCase;
use Pixelated\Streamline\Tests\TestCase;
use Pixelated\Streamline\Tests\Unit\Traits\Utils;

$_ENV['TEST_DIR'] = __DIR__;

/**
 * @see \Illuminate\Testing\PendingCommand
 * @see \Pixelated\Streamline\Tests\LaravelTestCase Some setup is done in here
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
