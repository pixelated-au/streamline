<?php

use Illuminate\Support\Facades\Config;

it('should return an array when given a valid comma-separated string', function() {
    Config::set('test.key', 'value1,value2,value3');

    $result = Config::commaToArray('test.key');

    expect($result)->toBe(['value1', 'value2', 'value3']);
});

it('should return the original array when given an array input', function() {
    Config::set('test.key', ['value1', 'value2', 'value3']);
    $result = Config::commaToArray('test.key');

    expect($result)->toBe(['value1', 'value2', 'value3']);
});

it('should throw an exception when given a non-string, non-array input', function() {
    Config::set('test.key', 42);
    expect(fn() => Config::commaToArray('test.key'))
        ->toThrow(Exception::class, 'Invalid value (42) for test.key. Expected a comma-separated list or an array.');
});

it('drops empty entries so an empty string is an empty list', function() {
    Config::set('test.key', '');

    $result = Config::commaToArray('test.key');
    expect($result)->toBe([]);
});

it('should handle whitespace in the comma-separated string correctly', function() {
    Config::set('test.key', ' value1 , value2,  value3 ');

    $result = Config::commaToArray('test.key');

    expect($result)->toBe(['value1', 'value2', 'value3']);
});

it('drops empty and whitespace-only entries such as a trailing comma', function(string $value, array $expected) {
    Config::set('test.key', $value);

    expect(Config::commaToArray('test.key'))->toBe($expected);
})->with([
    'trailing comma'   => ['.env,', ['.env']],
    'doubled comma'    => ['a,,b', ['a', 'b']],
    'whitespace entry' => ['a, ,b', ['a', 'b']],
    'only commas'      => [',,', []],
    'only whitespace'  => ['  ', []],
]);

it('parses a JSON array string as a list', function() {
    Config::set('test.key', '[".env", "storage/app", ""]');

    expect(Config::commaToArray('test.key'))->toBe(['.env', 'storage/app']);
});

it('rejects a JSON array containing non-string entries', function() {
    Config::set('test.key', '[".env", 5]');

    expect(fn() => Config::commaToArray('test.key'))->toThrow(Exception::class, 'test.key');
});
