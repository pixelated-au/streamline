<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

it('checks that the streamline logging is enabled', function() {
    $logged = [];

    Log::listen(function(MessageLogged $event) use (&$logged) {
        $logged[] = $event;
    });

    expect(config('logging.channels.streamline'))->toBeArray()->not->toBeEmpty();

    Log::channel('streamline')->info('test 123');

    $matching = array_values(array_filter($logged, fn(MessageLogged $event) => $event->message === 'test 123'));

    expect($matching)->toHaveCount(1)
        ->and($matching[0]->level)->toBe('info');
});
