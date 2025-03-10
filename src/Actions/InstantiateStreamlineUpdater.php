<?php

/** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Actions;

use Closure;
use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Factories;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

class InstantiateStreamlineUpdater
{
    /** @var class-string */
    private readonly string $runnerClass;

    public function __construct(
        private readonly Factories\ProcessFactory $process,
        // TODO restore this after upgrading to Laravel 11
        //        #[ConfigAttribute('streamline.runner_class')]
        //        private readonly string           $runnerClass,
    ) {
        // TODO restore this after upgrading to Laravel 11
        $this->runnerClass = Config::get('streamline.runner_class');
    }

    /**
     * @param  Closure(string, string): void  $callback
     */
    public function execute(string $versionToInstall, Closure $callback): void
    {
        $classFilePath = $this->getClassFilePath();

        $script         = sprintf('require "%s"; (new %s)->run();', $classFilePath, $this->runnerClass);
        $protectedPaths = $this->parseArray([
            ...Config::commaToArray('streamline.protected_files'),
        ]);

        $this->process
            ->invoke($script)
            ->setEnv([
                'TEMP_DIR'                 => config('streamline.work_temp_dir'),
                'LARAVEL_BASE_PATH'        => base_path(),
                'PUBLIC_DIR_NAME'          => public_path(),
                'FRONT_END_BUILD_DIR'      => config('streamline.laravel_build_dir_name'),
                'INSTALLING_VERSION'       => $versionToInstall,
                'PROTECTED_PATHS'          => $protectedPaths,
                'DIR_PERMISSION'           => (int) config('streamline.directory_permissions'),
                'FILE_PERMISSION'          => (int) config('streamline.file_permissions'),
                'OLD_RELEASE_ARCHIVE_PATH' => config('streamline.backup_dir'),
                'DO_RETAIN_OLD_RELEASE'    => (bool) config('streamline.retain_old_releases'),
                'IS_TESTING'               => defined('IS_TESTING'), // Set in phpunit config XML file.
            ])->run($callback);
    }

    protected function parseArray(array|string $input): string
    {
        if (is_array($input)) {
            return '["' . implode('","', $input) . '"]';
        }

        return $input;
    }

    protected function getClassFilePath(): string
    {
        try {
            return (new ReflectionClass($this->runnerClass))->getFileName();
        } catch (ReflectionException $e) {
            throw new RuntimeException("Error instantiating updater class '$this->runnerClass': " . $e->getMessage());
        }
    }
}
