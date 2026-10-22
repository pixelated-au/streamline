<?php

use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Services\FinishUpdate;
use Symfony\Component\Process\Process;

it('tells the finish-update process which version it is meant to have installed', function() {
    $captured = null;

    $this->app->bind(Process::class, function($app, array $params) use (&$captured) {
        $captured = $params;
        $mock     = Mockery::mock(Process::class);
        $mock->shouldReceive('run')->andReturn(0);
        $mock->shouldReceive('isSuccessful')->andReturnTrue();
        $mock->shouldReceive('getOutput')->andReturn('ok');

        return $mock;
    });
    Event::fake();

    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getBasePath')->andReturn(base_path());
    $builder->shouldReceive('getRequestedVersion')->andReturn('v3.1.0');
    $builder->shouldReceive('isUpdateCompleted')->andReturnTrue();

    resolve(FinishUpdate::class, ['builder' => $builder])();

    expect($captured['env'] ?? null)->toBe([
        'STREAMLINE_EXPECTED_VERSION' => 'v3.1.0',
        'STREAMLINE_UPDATE_COMPLETED' => '1',
    ]);
});

it('tells the finish-update process the update did not complete when the pipeline aborted', function() {
    $captured = null;

    $this->app->bind(Process::class, function($app, array $params) use (&$captured) {
        $captured = $params;
        $mock     = Mockery::mock(Process::class);
        $mock->shouldReceive('run')->andReturn(0);
        $mock->shouldReceive('isSuccessful')->andReturnTrue();
        $mock->shouldReceive('getOutput')->andReturn('ok');

        return $mock;
    });
    Event::fake();

    $builder = Mockery::mock(UpdateBuilderInterface::class);
    $builder->shouldReceive('getBasePath')->andReturn(base_path());
    $builder->shouldReceive('getRequestedVersion')->andReturn('v1.0.0');
    $builder->shouldReceive('isUpdateCompleted')->andReturnFalse();

    resolve(FinishUpdate::class, ['builder' => $builder])();

    expect($captured['env']['STREAMLINE_UPDATE_COMPLETED'] ?? null)->toBe('0');
});
