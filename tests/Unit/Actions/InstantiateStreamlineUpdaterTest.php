<?php

use Illuminate\Support\Facades\Config;
use Mockery\LegacyMockInterface;
use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;
use Pixelated\Streamline\Factories\ProcessFactory;
use Pixelated\Streamline\Updater\UpdateBuilder;
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
    Config::set('streamline.carry_forward_paths', 'public/storage, uploads/');
    Config::set('streamline.protected_file_permissions', '0600');

    $expectedEnv = [
        'TEMP_DIR'                  => config('streamline.work_temp_dir'),
        'LARAVEL_BASE_PATH'         => base_path(),
        'PUBLIC_DIR_NAME'           => public_path(),
        'FRONT_END_BUILD_DIR'       => config('streamline.laravel_build_dir_name'),
        'INSTALLING_VERSION'        => $versionToInstall,
        'COMPOSER_PATH'             => '/dev/null',
        'PROTECTED_PATHS'           => '["path1","path2"]',
        'CARRY_FORWARD_PATHS'       => '["public/storage","uploads/"]',
        'DIR_PERMISSION'            => '493',
        'FILE_PERMISSION'           => '420',
        'PROTECTED_FILE_PERMISSION' => '384',
        // The archive path is supplied by the backup step, not derived from the backup directory.
        'OLD_RELEASE_ARCHIVE_PATH' => '/path/to/backup-20260101_000000.zip',
        'DO_RETAIN_OLD_RELEASE'    => '1',
        'IS_TESTING'               => '1',
    ];

    $process        = mockProcess();
    $processFactory = mockProcessFactory($classPath, $runnerClass, $process);
    $updater        = mockUpdaterClass($processFactory, $classPath, $runnerClass);

    $updater->execute($versionToInstall, '/dev/null', $callback, '/path/to/backup-20260101_000000.zip');

    expect($process->capturedEnv)->toEqual($expectedEnv);
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

function mockProcess(int $exitCode = 0): Process
{
    // An anonymous subclass records the exact environment instead of only a subset of it.
    $process = new class(['true']) extends Process
    {
        public array $capturedEnv = [];

        public int $exit = 0;

        public function setEnv(array $env): static
        {
            $this->capturedEnv = $env;

            return $this;
        }

        public function run(?callable $callback = null, array $env = []): int
        {
            return $this->exit;
        }
    };
    $process->exit = $exitCode;

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

it('fails when the updater process exits with a non-zero code, including its captured output', function() {
    $factory = Mockery::mock(ProcessFactory::class);
    $process = mockProcess(255);
    $factory->shouldReceive('invoke')->andReturn($process);

    $updater = Mockery::mock(InstantiateStreamlineUpdater::class, [$factory, 'TestRunnerClass'])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $updater->shouldReceive('getClassFilePath')->andReturn('/path/to/TestRunnerClass.php');

    expect(fn() => $updater->execute('2.0.0', '/dev/null', fn() => null))
        ->toThrow(RuntimeException::class, 'failed with exit code 255');
});

it('refuses paths that are empty, current-directory or escape the installation root', function(string $path) {
    $updater = new InstantiateStreamlineUpdater(Mockery::mock(ProcessFactory::class), 'Anything');
    $closure = Closure::bind(fn() => $this->parseArray(['.env', $path]), $updater, $updater);

    expect($closure)->toThrow(RuntimeException::class);
})->with(['empty' => [''], 'dot' => ['.'], 'dot slash' => ['./'], 'spaces' => ['   '], 'absolute' => ['/etc'], 'backslash' => ['\\x'], 'parent' => ['..'], 'nested parent' => ['a/../b'], 'windows parent' => ['a\\..\\b']]);

it('accepts ordinary relative paths', function() {
    $updater = new InstantiateStreamlineUpdater(Mockery::mock(ProcessFactory::class), 'Anything');
    $closure = Closure::bind(fn() => $this->parseArray(['.env', 'storage/app', './public/storage', '.htaccess', 'a..b']), $updater, $updater);

    expect(json_decode($closure(), true))->toBe(['.env', 'storage/app', './public/storage', '.htaccess', 'a..b']);
});

it('reads an octal permission with a 0o prefix', function() {
    $updater = new InstantiateStreamlineUpdater(Mockery::mock(ProcessFactory::class), 'Anything');
    $parse   = Closure::bind(fn(mixed $permission) => $this->parsePermission($permission, 0600), $updater, $updater);

    expect($parse('0o644'))->toBe(0644)
        ->and($parse('644'))->toBe(0644)
        ->and($parse('0644'))->toBe(0644)
        ->and($parse('abc'))->toBe(0600)
        ->and($parse('789'))->toBe(0600);
});

it('rejects a work or backup directory located inside the installation', function(string $key, string $suffix) {
    Config::set("streamline.$key", base_path($suffix));

    $builder = new UpdateBuilder;

    expect(fn() => $builder->getWorkTempDir())
        ->toThrow(RuntimeException::class, "streamline.$key");
})->with([
    'work dir in base'    => ['work_temp_dir', '.streamline_tmp'],
    'work dir is base'    => ['work_temp_dir', ''],
    'backup dir in base'  => ['backup_dir', 'backups/zips'],
    'work dir via dotdot' => ['work_temp_dir', 'storage/../tmp'],
]);

it('accepts work and backup directories outside the installation', function() {
    Config::set('streamline.work_temp_dir', dirname(base_path()) . '/.streamline_tmp');
    Config::set('streamline.backup_dir', dirname(base_path()) . '/' . basename(base_path()) . '_backups');

    expect((new UpdateBuilder)->getWorkTempDir())
        ->toBe(dirname(base_path()) . '/.streamline_tmp');
});
