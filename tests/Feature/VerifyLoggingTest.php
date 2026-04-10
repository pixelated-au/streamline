<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

it('checks that the streamline logging is enabled', function() {
    Log::listen(function(MessageLogged $event) {
        expect($event->message)->toBe('test 123');
    });
    Log::channel('streamline')->info('test 123');
});
