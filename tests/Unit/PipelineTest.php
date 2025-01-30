<?php

use Pixelated\Streamline\Pipeline\Pipeline;
use Pixelated\Streamline\Updater\UpdateBuilder;

it('can process function pipes', closure: function () {
    $this->expectOutputString('Test pipe');
    $result = (new Pipeline(new UpdateBuilder()))
        ->through([function () {
            echo 'Test pipe';
        }])
        ->then(fn() => true);

    $this->assertTrue($result);
});

it('can handle pipe exceptions properly', closure: function () {
    $this->expectOutputString('Caught exception: Test exception');
    $result = (new Pipeline(new UpdateBuilder()))
        ->through([function () {
            throw new RuntimeException('Test exception');
        }])
        ->catch(function (Throwable $e) {
            echo 'Caught exception: ' . $e->getMessage();
            return false;
        })
        ->then(function () {
            return true;
        });
    $this->assertFalse($result);
});

it('will throw pipe exceptions properly', closure: function () {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Test throwing exception');
    (new Pipeline(new UpdateBuilder()))
        ->through([function () {
            throw new RuntimeException('Test throwing exception');
        }])
        ->then(function () {
            return true;
        });
});

it('can handle "then" exceptions properly', closure: function () {
    $this->expectOutputString('Caught exception: Test (then) exception');
    $result = (new Pipeline(new UpdateBuilder()))
        ->through([])
        ->catch(function (Throwable $e) {
            echo 'Caught exception: ' . $e->getMessage();
            return false;
        })
        ->then(function () {
            throw new RuntimeException('Test (then) exception');
        });
    $this->assertFalse($result);
});

it('will throw "then" exceptions properly', closure: function () {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Test throwing (then) exception');
    (new Pipeline(new UpdateBuilder()))
        ->through([])
        ->then(function () {
            throw new RuntimeException('Test throwing (then) exception');
        });
});


it('will throw an exception but still run the finally function', closure: function () {
    $this->expectOutputString('Caught exception: Test finally exception');

    $finallyRun = false;

    $result = (new Pipeline(new UpdateBuilder()))
        ->through([fn () => throw new RuntimeException('Test finally exception')])
        ->catch(function (Throwable $e) {
            echo 'Caught exception: '. $e->getMessage();
            return false;
        })
        ->finally(function () use (&$finallyRun) {
            $this->assertFalse($finallyRun);
            $finallyRun = true;
        })
        ->then(function () {
            // This should not be reached
            return true;
        });
    $this->asserttrue($finallyRun);
    $this->assertFalse($result);
});
