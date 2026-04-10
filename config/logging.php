<?php

/** @noinspection PhpUnusedParameterInspection */

return [
    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configuration of logging. Primarily used for debugging the deletion of
    | old assets during the cleanup process.
    |
    */

    'channels' => [
        'streamline' => [
            'driver' => 'single',
            'path'   => storage_path('logs/streamline.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
        ],
    ],
];
