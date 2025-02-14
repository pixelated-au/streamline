<?php

use Illuminate\Support\Facades\Config;

it('should return an array when given a valid comma-separated string', function () {
    Config::set('test.key', 'value1,value2,value3');

    $result = Config::commaToArray('test.key');

    expect($result)->toBe(['value1', 'value2', 'value3']);
});

it('should return the original array when given an array input', function () {
    Config::set('test.key', ['value1', 'value2', 'value3']);
    $result = Config::commaToArray('test.key');

    expect($result)->toBe(['value1', 'value2', 'value3']);
});

it('should throw an exception when given a non-string, non-array input', function () {
    Config::set('test.key', 42);
    expect(fn () => Config::commaToArray('test.key'))
        ->toThrow(Exception::class, 'Invalid value (42) for test.key. Expected a comma-separated list or an array.');
});

it('should return an empty array when given an empty string', function () {
    Config::set('test.key', '');

    $result = Config::commaToArray('test.key');
    expect($result)->toBe(['']);
});

it('should handle whitespace in the comma-separated string correctly', function () {
    Config::set('test.key', ' value1 , value2,  value3 ');

    $result = Config::commaToArray('test.key');

    expect($result)->toBe(['value1', 'value2', 'value3']);
});
