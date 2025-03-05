<?php

use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Actions\InstantiateStreamlineUpdater;
use Pixelated\Streamline\Factories\ProcessFactory;

it('should throw a RuntimeException when given an invalid class', function() {
    $filename = 'BrokenClass.php';

    $process = Mockery::mock(ProcessFactory::class);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Error instantiating updater class '$filename': Class \"$filename\" does not exist");

    // TODO remove this after upgrading to Laravel 11
    Config::set('streamline.runner_class', $filename);

    // TODO restore this after upgrading to Laravel 11
    //        (new InstantiateStreamlineUpdater($process, $nonExistentClassName))
    (new InstantiateStreamlineUpdater($process))
        ->execute('1.0.0', fn() => null);
});

it('should return the file path when a valid class is passed in', function() {
    $filename = 'ValidTestClass.php';
    Storage::fake();
    Storage::put($filename, '<?php class ValidTestClass {}');

    include Storage::path($filename);

    $process = Mockery::mock(ProcessFactory::class);

    /** @noinspection PhpUndefinedClassInspection */
    Config::set('streamline.runner_class', ValidTestClass::class);
    $updater = new InstantiateStreamlineUpdater($process);

    $pathInvokable = Closure::bind(fn() => $this->getClassFilePath(), $updater, $updater);
    $this->assertSame(Storage::path($filename), $pathInvokable());
});

it('can properly parse an array and string values', function() {
    $process = Mockery::mock(ProcessFactory::class);

    /** @noinspection PhpUndefinedClassInspection */
    Config::set('streamline.runner_class', ValidTestClass::class);
    $updater    = new InstantiateStreamlineUpdater($process);
    $arrayValue = Closure::bind(fn() => $this->parseArray(['one', 'two']), $updater, $updater);
    $this->assertSame('["one","two"]', $arrayValue());

    $arrayValue = Closure::bind(fn() => $this->parseArray('string of text'), $updater, $updater);
    $this->assertSame('string of text', $arrayValue());
});

it('should run the process and set all required environment variables correctly', function() {
    $this->expectNotToPerformAssertions();

    $process          = Mockery::mock(ProcessFactory::class);
    $callback         = function() {};
    $versionToInstall = '2.0.0';
    $classPath        = '/path/to/RunnerClass.php';

    Config::set('streamline.runner_class', 'TestRunnerClass');
    Config::set('streamline.laravel_build_dir_name', 'build_dir_value');
    Config::set('streamline.work_temp_dir', 'temp_dir_value');
    Config::set('streamline.backup_dir', 'backup_dir_value');
    Config::set('streamline.web_assets_max_file_size', 1000);
    Config::set('streamline.directory_permissions', 0755);
    Config::set('streamline.file_permissions', 0644);
    Config::set('streamline.retain_old_releases', true);
    Config::set('streamline.web_assets_filterable_file_types', 'jpg,png,gif');
    Config::set('streamline.protected_files', 'path1,path2');

    /** @var \Mockery\MockInterface&InstantiateStreamlineUpdater $updater */
    $updater = Mockery::mock(InstantiateStreamlineUpdater::class, [$process])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $updater->shouldReceive('getClassFilePath')
        ->once()
        ->andReturn($classPath);

    $expectedEnv = [
        'TEMP_DIR'                 => config('streamline.work_temp_dir'),
        'LARAVEL_BASE_PATH'        => base_path(),
        'PUBLIC_DIR_NAME'          => public_path(),
        'FRONT_END_BUILD_DIR'      => config('streamline.laravel_build_dir_name'),
        'INSTALLING_VERSION'       => $versionToInstall,
        'PROTECTED_PATHS'          => '["path1","path2"]',
        'DIR_PERMISSION'           => 0755,
        'FILE_PERMISSION'          => 0644,
        'OLD_RELEASE_ARCHIVE_PATH' => config('streamline.backup_dir'),
        'DO_RETAIN_OLD_RELEASE'    => true,
        'IS_TESTING'               => true,
    ];

    $expectedScript = "<?php require_once '$classPath'; (new TestRunnerClass())->run(); ?>";

    $phpProcess = Mockery::mock(Symfony\Component\Process\Process::class);

    $process->shouldReceive('invoke')
        ->with($expectedScript)
        ->once()
        ->andReturn($phpProcess);

    $phpProcess->shouldReceive('setEnv')
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

    $phpProcess->shouldReceive('run')
        ->once()
        ->with($callback);

    $updater->execute($versionToInstall, $callback);
});
