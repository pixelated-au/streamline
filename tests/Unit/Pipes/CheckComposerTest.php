<?php

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
