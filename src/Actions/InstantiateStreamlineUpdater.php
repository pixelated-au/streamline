<?php /** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Actions;

use Closure;
use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Wrappers;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

class InstantiateStreamlineUpdater
{
    /** @var class-string */
    private readonly string $runnerClass;

    /**
     * @param \Pixelated\Streamline\Wrappers\Process $process
//     * @param class-string $runnerClass
     */
    public function __construct(
        private readonly Wrappers\Process $process,
        //TODO restore this after upgrading to Laravel 11
//        #[ConfigAttribute('streamline.runner_class')]
//        private readonly string           $runnerClass,
    )
    {
        //TODO restore this after upgrading to Laravel 11
        $this->runnerClass = Config::get('streamline.runner_class');
    }

    /**
     * @param Closure(string, string): void $callback
     */
    public function execute(string $versionToInstall, Closure $callback): void
    {
        $path = $this->getClassFilePath();

        $script = "<?php require_once '$path'; (new $this->runnerClass())->run(); ?>";

        $this->process
            ->invoke($script)
            ->setEnv([
                'BASE_PATH'               => base_path(),
                'SOURCE_DIR'              => base_path('source'),
                'PUBLIC_DIR_NAME'         => public_path(),
                'FRONT_END_BUILD_DIR'     => config('streamline.laravel_build_dir_name'),
                'TEMP_DIR'                => base_path(config('streamline.work_temp_dir')),
                'INSTALLING_VERSION'      => $versionToInstall,
                'BACKUP_DIR'              => config('streamline.backup_dir'),
                'MAX_FILE_SIZE'           => (int)config('streamline.web_assets_max_file_size'),
                'DIR_PERMISSION'          => (int)config('streamline.directory_permissions'),
                'FILE_PERMISSION'         => (int)config('streamline.file_permissions'),
                'RETAIN_OLD_RELEASE'      => (bool)config('streamline.retain_old_releases'),
                'ALLOWED_FILE_EXTENSIONS' => $this->parseArray(Config::commaToArray('streamline.web_assets_filterable_file_types')),
                'PROTECTED_PATHS'         => $this->parseArray(Config::commaToArray('streamline.protected_files')),
                'IS_TESTING'              => defined('IS_TESTING'), // Set in phpunit config XML file.
            ])
            ->run($callback);
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
