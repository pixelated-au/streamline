<?php

use Composer\IO\IOInterface;
use Composer\Script\Event;
use phpmock\mockery\PHPMockery;
use Pixelated\Streamline\Install\RequiredFunctionsCheck;

beforeEach(function () {
    $this->ns = 'Pixelated\\Streamline\\Install';
});

it('can validate that the system function is enabled',
    /**
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    function () {
        PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('');
        PHPMockery::mock($this->ns, 'function_exists')->with('system')->andReturnTrue();
        PHPMockery::mock($this->ns, 'system')->with('echo test')->andReturn('test');

        $composerEvent = $this->createStub(Event::class);

        $ioSpy = $this->spy(IOInterface::class);
        $composerEvent->method('getIO')->willReturn($ioSpy);

        $result = RequiredFunctionsCheck::run($composerEvent);
        $ioSpy->shouldNotHaveReceived('warning');
        $this->assertTrue($result);
    }
);

it('can validate that the system function is not enabled because it is in disable_functions',
    /**
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    function () {
        PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('system,exec');
        PHPMockery::mock($this->ns, 'function_exists')->with('system')->andReturnTrue();
        PHPMockery::mock($this->ns, 'system')->with('echo test')->andReturn('test');

        $composerEvent = $this->createStub(Event::class);

        $ioSpy = $this->spy(IOInterface::class);
        $composerEvent->method('getIO')->willReturn($ioSpy);

        $result = RequiredFunctionsCheck::run($composerEvent);
        $ioSpy->shouldHaveReceived(
            'warning',
            fn(string $args) => str_starts_with($args, 'The PHP system()')
                && str_ends_with($args, "function is listed in PHP's disable_functions() check. This means someone has deliberately disabled it, possibly a system administrator.")
        );
        $this->assertFalse($result);
    }
);

it('can validate that the system function is not enabled because it is not listed as a function',
    /**
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    function () {
        PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('');
        PHPMockery::mock($this->ns, 'function_exists')->with('system')->andReturnFalse();
        PHPMockery::mock($this->ns, 'system')->with('echo test')->andReturn('test');

        $composerEvent = $this->createStub(Event::class);

        $ioSpy = $this->spy(IOInterface::class);
        $composerEvent->method('getIO')->willReturn($ioSpy);

        $result = RequiredFunctionsCheck::run($composerEvent);
        $ioSpy->shouldHaveReceived(
            'warning',
            fn(string $args) => str_ends_with($args, 'The function_exists("system") check returns false')
        );
        $this->assertFalse($result);
    }
);

it('can validate that the system function is not enabled because it cannot execute commands',
    /**
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    function () {
        PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('');
        PHPMockery::mock($this->ns, 'function_exists')->with('system')->andReturnTrue();
        PHPMockery::mock($this->ns, 'system')
            ->with('echo test')
            ->andThrow(new Exception('Whoopsy daisy!'));

        $composerEvent = $this->createStub(Event::class);

        $ioSpy = $this->spy(IOInterface::class);
        $composerEvent->method('getIO')->willReturn($ioSpy);

        $result = RequiredFunctionsCheck::run($composerEvent);
        $ioSpy->shouldHaveReceived(
            'warning',
            fn(string $args) => str_ends_with($args, 'Whoopsy daisy!')
        );
        $this->assertFalse($result);
    }
);

it('returns an error message when the system function is run but does not return the expected output',
    /**
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    function () {
        PHPMockery::mock($this->ns, 'ini_get')->with('disable_functions')->andReturn('');
        PHPMockery::mock($this->ns, 'function_exists')->with('system')->andReturnTrue();
        PHPMockery::mock($this->ns, 'system')
            ->with('echo test')
            ->andReturn('Not the expected output');

        $composerEvent = $this->createStub(Event::class);

        $ioSpy = $this->spy(IOInterface::class);
        $composerEvent->method('getIO')->willReturn($ioSpy);

        $result = RequiredFunctionsCheck::run($composerEvent);
        $ioSpy->shouldHaveReceived(
            'warning',
            fn(string $args) => str_contains($args, 'Unable to run the system() function')
        );
        $this->assertFalse($result);
    }
);
