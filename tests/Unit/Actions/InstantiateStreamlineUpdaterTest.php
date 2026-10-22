<?php

use Illuminate\Support\Facades\Config;
use Mockery\LegacyMockInterface;
use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;
use Pixelated\Streamline\Factories\ProcessFactory;
use Symfony\Component\Process\Process;

it('should throw a RuntimeException when given an invalid class', function() {
    $filename = 'BrokenClass';

    $process = Mockery::mock(ProcessFactory::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Error instantiating updater class '$filename': Class \"$filename\" does not exist");

    // TODO remove this after upgrading to Laravel 11
    //    Config::set('streamline.runner_class', $filename);

    // TODO restore this after upgrading to Laravel 11
    (new InstantiateStreamlineUpdater($process, $filename))
//    (new InstantiateStreamlineUpdater($process))
        ->execute('1.0.0', '', fn() => null);
});

it('should return the file path when a valid class is passed in', function() {
    $filename = 'ValidTestClass.php';
    Storage::fake();
    Storage::put($filename, '<?php class ValidTestClass {}');

    include Storage::path($filename);

    $process = Mockery::mock(ProcessFactory::class);

    /** @noinspection PhpUndefinedClassInspection */
    $updater = new InstantiateStreamlineUpdater($process, ValidTestClass::class);

    $pathInvokable = Closure::bind(fn() => $this->getClassFilePath(), $updater, $updater);
    $this->assertSame(Storage::path($filename), $pathInvokable());
});

it('can properly parse an array and string values', function() {
    $process = Mockery::mock(ProcessFactory::class);

    /** @noinspection PhpUndefinedClassInspection */
    $updater    = new InstantiateStreamlineUpdater($process, ValidTestClass::class);
    $arrayValue = Closure::bind(fn() => $this->parseArray(['one', 'two']), $updater, $updater);
    $this->assertSame('["one","two"]', $arrayValue());

    $arrayValue = Closure::bind(fn() => $this->parseArray('string of text'), $updater, $updater);
    $this->assertSame('string of text', $arrayValue());
});

it('should run the process and set all required environment variables correctly', function() {
    $this->expectNotToPerformAssertions();

    $callback         = function() {};
    $versionToInstall = '2.0.0';
    $runnerClass      = 'TestRunnerClass';
    $classPath        = "/path/to/$runnerClass.php";

    Config::set('streamline.laravel_build_dir_name', 'build_dir_value');
    Config::set('streamline.work_temp_dir', 'temp_dir_value');
    Config::set('streamline.backup_dir', 'backup_dir_value');
    Config::set('streamline.web_assets_max_file_size', 1000);
    Config::set('streamline.directory_permissions', 0755);
    Config::set('streamline.file_permissions', 0644);
    Config::set('streamline.retain_old_releases', true);
    Config::set('streamline.web_assets_filterable_file_types', 'jpg,png,gif');
    Config::set('streamline.protected_files', 'path1,path2');

    $expectedEnv = [
        'TEMP_DIR'                  => config('streamline.work_temp_dir'),
        'LARAVEL_BASE_PATH'         => base_path(),
        'PUBLIC_DIR_NAME'           => public_path(),
        'FRONT_END_BUILD_DIR'       => config('streamline.laravel_build_dir_name'),
        'INSTALLING_VERSION'        => $versionToInstall,
        'COMPOSER_PATH'             => '/dev/null',
        'PROTECTED_PATHS'           => '["path1","path2"]',
        'DIR_PERMISSION'            => '493',
        'FILE_PERMISSION'           => '420',
        'PROTECTED_FILE_PERMISSION' => '384',
        // The archive path is supplied by the backup step, not derived from the backup directory.
        'OLD_RELEASE_ARCHIVE_PATH' => '/path/to/backup-20260101_000000.zip',
        'DO_RETAIN_OLD_RELEASE'    => '1',
        'IS_TESTING'               => '1',
    ];

    $process        = mockProcess($expectedEnv, $callback);
    $processFactory = mockProcessFactory($classPath, $runnerClass, $process);
    $updater        = mockUpdaterClass($processFactory, $classPath, $runnerClass);

    $updater->execute($versionToInstall, '/dev/null', $callback, '/path/to/backup-20260101_000000.zip');
});

function mockProcessFactory(string $classPath, string $runnerClass, $process): ProcessFactory
{
    $processFactory = Mockery::mock(ProcessFactory::class);

    $processFactory->shouldReceive('invoke')
        ->with(sprintf('require "%s"; (new %s)->run();', $classPath, $runnerClass))
        ->once()
        ->andReturn($process);

    return $processFactory;
}

function mockProcess(array $expectedEnv, Closure $callback): Process
{
    $process = Mockery::mock(Process::class);

    $process->shouldReceive('setEnv')
        ->once()
        ->with(Mockery::on(function($env) use ($expectedEnv) {
            foreach ($expectedEnv as $key => $value) {
                if ($env[$key] !== $value) {
                    return false;
                }
            }

            return true;
        }))
        ->andReturnSelf();

    $process->shouldReceive('run')
        ->once()
        ->with($callback);

    return $process;
}

function mockUpdaterClass(
    ProcessFactory $processFactory,
    string $classPath,
    string $runnerClass
): InstantiateStreamlineUpdater|LegacyMockInterface {
    $updater = Mockery::mock(InstantiateStreamlineUpdater::class, [$processFactory, $runnerClass])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $updater->shouldReceive('getClassFilePath')
        ->once()
        ->andReturn($classPath);

    return $updater;
}

it('refuses a runner class name that could break out of the generated php snippet', function() {
    $process = Mockery::mock(ProcessFactory::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Error: The configured runner class \'Broken"; system("id"); //\' is not a valid class name.');

    (new InstantiateStreamlineUpdater($process, 'Broken"; system("id"); //'))
        ->execute('1.0.0', '', fn() => null);
});

it('refuses protected paths that escape the installation root', function() {
    $process = Mockery::mock(ProcessFactory::class);

    /** @noinspection PhpUndefinedClassInspection */
    $updater = new InstantiateStreamlineUpdater($process, ValidTestClass::class);
    $closure = Closure::bind(fn() => $this->parseArray(['../../etc/passwd']), $updater, $updater);

    expect($closure)->toThrow(RuntimeException::class, 'must be relative to the installation root');
});

it('encodes protected paths containing characters that would break hand-built JSON', function() {
    $process = Mockery::mock(ProcessFactory::class);

    /** @noinspection PhpUndefinedClassInspection */
    $updater = new InstantiateStreamlineUpdater($process, ValidTestClass::class);
    $closure = Closure::bind(fn() => $this->parseArray(['a"quote.txt', 'plain.txt']), $updater, $updater);

    expect(json_decode($closure(), true, 512, JSON_THROW_ON_ERROR))->toBe(['a"quote.txt', 'plain.txt']);
});

it('reads permissions as octal, in whatever form they arrive', function(mixed $value, int $expected) {
    $process = Mockery::mock(ProcessFactory::class);

    /** @noinspection PhpUndefinedClassInspection */
    $updater = new InstantiateStreamlineUpdater($process, ValidTestClass::class);
    $parse   = Closure::bind(fn(mixed $permission) => $this->parsePermission($permission, 0644), $updater, $updater);

    expect($parse($value))->toBe($expected);
})->with([
    // The config default is already an octal int literal.
    'octal int from config' => [0644, 0644],
    // From the .env file it arrives as a string. A plain (int) cast would make this 644 decimal,
    // which chmod() reads as mode 01204 - sticky bit set, and unreadable by the group and others.
    'octal string from .env'       => ['0644', 0644],
    'octal string without prefix'  => ['755', 0755],
    'directory default'            => ['0700', 0700],
    'unparseable value falls back' => ['not-a-mode', 0644],
]);

it('passes booleans to the updater as strings so Symfony does not drop them', function() {
    // Symfony's Process omits any env value that is exactly false, so a boolean false never
    // reached the child process at all. The updater then reported the variable as unset and
    // aborted, which meant turning retain_old_releases off broke every update.
    $process = Mockery::mock(ProcessFactory::class);

    /** @noinspection PhpUndefinedClassInspection */
    $updater   = new InstantiateStreamlineUpdater($process, ValidTestClass::class);
    $stringify = Closure::bind(fn(array $env) => $this->stringifyEnv($env), $updater, $updater);

    expect($stringify(['A' => false, 'B' => true, 'C' => 0644, 'D' => 'text']))
        ->toBe(['A' => '0', 'B' => '1', 'C' => '420', 'D' => 'text']);
});
