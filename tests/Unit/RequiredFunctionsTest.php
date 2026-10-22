<?php

use Composer\IO\IOInterface;
use Composer\Script\Event;
use phpmock\mockery\PHPMockery;
use Pixelated\Streamline\Install\RequiredFunctionsCheck;

beforeEach(function() {
    $this->ns = 'Pixelated\\Streamline\\Install';
});

/**
 * The check covers every function in REQUIRED_FUNCTIONS, so all of them have to be stubbed even
 * when a test is only interested in one. A single mock handles every argument, because php-mock
 * permits only one mock per function per namespace.
 */
function mockFunctionsExist(string $ns, array $overrides = []): void
{
    PHPMockery::mock($ns, 'function_exists')
        ->andReturnUsing(fn(string $function) => $overrides[$function] ?? true);
}

function composerEventWithIoSpy(): array
{
    $event = test()->createStub(Event::class);
    $ioSpy = test()->spy(IOInterface::class);
    $event->method('getIO')->willReturn($ioSpy);

    return [$event, $ioSpy];
}

it('can validate that the required functions are enabled', function() {
    PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('');
    mockFunctionsExist($this->ns);
    PHPMockery::mock($this->ns, 'exec')->with('echo test')->andReturn('test');

    [$composerEvent, $ioSpy] = composerEventWithIoSpy();

    $result = RequiredFunctionsCheck::run($composerEvent);
    $ioSpy->shouldNotHaveReceived('warning');
    $this->assertTrue($result);
});

it('reports exec() when it is listed in disable_functions', function() {
    PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('exec,proc_open');
    mockFunctionsExist($this->ns);
    PHPMockery::mock($this->ns, 'exec')->with('echo test')->andReturn('test');

    [$composerEvent, $ioSpy] = composerEventWithIoSpy();

    $result = RequiredFunctionsCheck::run($composerEvent);
    $ioSpy->shouldHaveReceived(
        'warning',
        fn(string $args) => str_contains($args, "exec() is listed in PHP's disable_functions() check")
    );
    $this->assertFalse($result);
});

it('reports proc_open() when only that one is disabled', function() {
    // The previous implementation checked system() instead, so a configuration that disabled
    // proc_open() while leaving system() enabled was reported as healthy.
    PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('proc_open');
    mockFunctionsExist($this->ns);
    PHPMockery::mock($this->ns, 'exec')->with('echo test')->andReturn('test');

    [$composerEvent, $ioSpy] = composerEventWithIoSpy();

    $result = RequiredFunctionsCheck::run($composerEvent);
    $ioSpy->shouldHaveReceived(
        'warning',
        fn(string $args) => str_contains($args, "proc_open() is listed in PHP's disable_functions() check")
    );
    $this->assertFalse($result);
});

it('reports a required function that does not exist', function() {
    PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('');
    mockFunctionsExist($this->ns, ['exec' => false]);
    PHPMockery::mock($this->ns, 'exec')->with('echo test')->andReturn('test');

    [$composerEvent, $ioSpy] = composerEventWithIoSpy();

    $result = RequiredFunctionsCheck::run($composerEvent);
    $ioSpy->shouldHaveReceived(
        'warning',
        fn(string $args) => str_ends_with($args, 'The function_exists("exec") check returns false')
    );
    $this->assertFalse($result);
});

it('reports when commands cannot be executed due to an exception', function() {
    PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('');
    mockFunctionsExist($this->ns);
    PHPMockery::mock($this->ns, 'exec')
        ->with('echo test')
        ->andThrow(new Exception('Whoopsy daisy!'));

    [$composerEvent, $ioSpy] = composerEventWithIoSpy();

    $result = RequiredFunctionsCheck::run($composerEvent);
    $ioSpy->shouldHaveReceived(
        'warning',
        fn(string $args) => str_ends_with($args, 'Whoopsy daisy!')
    );
    $this->assertFalse($result);
});

it('returns an error message when exec runs but does not return the expected output', function() {
    PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('');
    mockFunctionsExist($this->ns);
    PHPMockery::mock($this->ns, 'exec')
        ->with('echo test')
        ->andReturn('Not the expected output');

    [$composerEvent, $ioSpy] = composerEventWithIoSpy();

    $result = RequiredFunctionsCheck::run($composerEvent);
    $ioSpy->shouldHaveReceived(
        'warning',
        fn(string $args) => str_contains($args, 'Unable to run the exec() function')
    );
    $this->assertFalse($result);
});
