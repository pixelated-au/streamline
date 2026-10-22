<?php

use Illuminate\Support\Facades\Process;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\CheckComposer;
use Pixelated\Streamline\Tests\Feature\Traits\CheckComposerPath;

pest()->uses(CheckComposerPath::class);

it('confirm the composer path is valid', function() {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getComposerPath')->andReturn('composer');
    $builder->shouldReceive('setComposerPath')->with('/usr/bin/composer');

    $this->mockComposerPath('/usr/bin/composer');

    $checkComposer = new CheckComposer;
    $result        = $checkComposer($builder);

    expect($result)->toBe($builder);
});

it('keeps an explicitly supplied composer path instead of replacing it with the output of `which`', function() {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getComposerPath')->andReturn('/dev/null');
    // The path given via --composer must survive. Previously `which` clobbered it, which broke the
    // very case the option exists for: composer not being on the PATH.
    $builder->shouldReceive('setComposerPath')->once()->with('/dev/null');

    Process::fake([
        '*-V*'    => Process::result('Composer version 2.0.0'),
        '*which*' => Process::result('/usr/bin/a-completely-different-composer'),
    ]);

    expect((new CheckComposer)($builder))->toBe($builder);
});

it('throws when composer cannot be executed', function() {
    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getComposerPath')->andReturn('/not/composer');

    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    expect(fn() => (new CheckComposer)($builder))
        ->toThrow(RuntimeException::class, 'Error: Cannot find composer.');
});
