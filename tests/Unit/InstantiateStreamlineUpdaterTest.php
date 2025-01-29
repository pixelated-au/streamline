<?php

use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;
use Pixelated\Streamline\Wrappers\Process;

it('should throw a RuntimeException when given a non-existent class name', function () {
    $classPath = sys_get_temp_dir() . '/BrokenClass.php';
    File::put($classPath, 'invalid class file');

    $process              = Mockery::mock(Process::class);
    $nonExistentClassName = $classPath;

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Error instantiating updater class '$nonExistentClassName': Class \"$nonExistentClassName\" does not exist");

    try {
        (new InstantiateStreamlineUpdater($process, $nonExistentClassName))
            ->execute('1.0.0', fn () => null);
    } finally {
        File::delete($classPath);
    }
});

it('should return the file path when a valid class is passed in', function () {
    $classPath = sys_get_temp_dir() . '/ValidTestClass.php';
    $x         = File::put($classPath, '<?php class ValidTestClass {}');
    include $classPath;

    $process = Mockery::mock(Process::class);

    try {
        /** @noinspection PhpUndefinedClassInspection */
        Config::set('streamline.runner_class', ValidTestClass::class);
        $updater = new InstantiateStreamlineUpdater($process, 'ValidTestClass');

        $pathInvokable = Closure::bind(fn() => $this->getClassFilePath(), $updater, $updater);
        $this->assertSame(realpath($classPath), $pathInvokable());
    } finally {
        File::delete($classPath);
    }
});

it('can properly parse an array and string values', function () {
    $process = Mockery::mock(Process::class);

    /** @noinspection PhpUndefinedClassInspection */
    Config::set('streamline.runner_class', ValidTestClass::class);
    $updater    = new InstantiateStreamlineUpdater($process, 'ValidTestClass');
    $arrayValue = Closure::bind(fn() => $this->parseArray(['one', 'two']), $updater, $updater);
    $this->assertSame('["one","two"]', $arrayValue());

    $arrayValue = Closure::bind(fn() => $this->parseArray('string of text'), $updater, $updater);
    $this->assertSame('string of text', $arrayValue());
});
