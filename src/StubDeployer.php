<?php

namespace Pixelated\Streamline;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Pixelated\Streamline\Facades\DeployableUpdaterStubTools;
use Pixelated\Streamline\Facades\Hasher;

class StubDeployer
{
    public const string      BASE_PATH_PLACEHOLDER               = '{{ BASE_PATH }}';
    public const string      HASH_PLACEHOLDER                    = '{{ HASH }}';
    public const string      SOURCE_DIR_PLACEHOLDER              = '{{ SOURCE_DIR }}';
    public const string      FRONT_END_BUILD_DIR_PLACEHOLDER     = '{{ FRONT_END_BUILD_DIR }}';
    public const string      PUBLIC_DIR_NAME_PLACEHOLDER         = '{{ PUBLIC_DIR_NAME }}';
    public const string      TEMP_DIR_PLACEHOLDER                = '{{ TEMP_DIR }}';
    public const string      INSTALLING_VERSION_PLACEHOLDER      = '{{ INSTALLING_VERSION }}';
    public const string      ALLOWED_FILE_EXTENSIONS_PLACEHOLDER = '{{ ALLOWED_FILE_EXTENSIONS }}';
    public const string      PROTECTED_PATHS_PLACEHOLDER         = '{{ PROTECTED_PATHS }}';
    public const string      MAX_FILE_SIZE_PLACEHOLDER           = '{{ MAX_FILE_SIZE }}';
    public const string      DIR_PERMISSION_PLACEHOLDER          = '{{ DIR_PERMISSION }}';
    public const string      FILE_PERMISSION_PLACEHOLDER         = '{{ FILE_PERMISSION }}';
    public const string      RETAIN_OLD_RELEASE_PLACEHOLDER      = '{{ RETAIN_OLD_RELEASE }}';

    public readonly string $deployedUpdateStubPath;
    public string $configClassStubContents;

    public function deploy(): self
    {
        DeployableUpdaterStubTools::confirmUpdateNotRunning();
        $updatePhpStubFileContents     = DeployableUpdaterStubTools::getUpdatePhpFileStub();
        $this->configClassStubContents = DeployableUpdaterStubTools::getConfigClassStub();
        $this->deployedUpdateStubPath  = DeployableUpdaterStubTools::generateUpdaterFileDeploymentPath();

        if (!File::exists(dirname($this->deployedUpdateStubPath))) {
            throw new InvalidArgumentException('The deploy directory ' . dirname($this->deployedUpdateStubPath) . ' does not exist. Configure it the streamline.php config file.');
        }

        if (!File::isWritable(dirname($this->deployedUpdateStubPath))) {
            throw new InvalidArgumentException('The deploy directory ' . dirname($this->deployedUpdateStubPath) . ' is not writeable.');
        }

        if (!File::isReadable(dirname($this->deployedUpdateStubPath))) {
            throw new InvalidArgumentException('The deploy directory ' . dirname($this->deployedUpdateStubPath) . ' is not readable.');
        }

        $configClass = $this->populateConfigStub()->configClassStubContents;
        File::put($this->deployedUpdateStubPath, str_replace('//{{ STREAMLINE_CONFIG_CLASS }}', $configClass, $updatePhpStubFileContents));
        return $this;
    }

    protected function populateConfigStub(): self
    {
        $this->setStubValue(self::HASH_PLACEHOLDER, Hasher::generate())
            ->setStubValue(self::BASE_PATH_PLACEHOLDER, base_path())
            ->setStubValue(self::SOURCE_DIR_PLACEHOLDER, base_path('source'))
            ->setStubValue(self::FRONT_END_BUILD_DIR_PLACEHOLDER, config('streamline.laravel_build_dir_name'))
            ->setStubValue(self::PUBLIC_DIR_NAME_PLACEHOLDER, config('streamline.updater_deploy_to_path'))
            ->setStubValue(self::TEMP_DIR_PLACEHOLDER, base_path(config('streamline.work_temp_dir')))
            ->setStubValue(self::INSTALLING_VERSION_PLACEHOLDER, config('streamline.installed_version'))
            ->setStubValue(self::ALLOWED_FILE_EXTENSIONS_PLACEHOLDER, Config::commaToArray('streamline.web_assets_filterable_file_types'))
            ->setStubValue(self::PROTECTED_PATHS_PLACEHOLDER, Config::commaToArray('streamline.protected_files'))
            ->setStubValue(self::MAX_FILE_SIZE_PLACEHOLDER, (int)config('streamline.web_assets_max_file_size'))
            ->setStubValue(self::DIR_PERMISSION_PLACEHOLDER, (int)config('streamline.directory_permissions'))
            ->setStubValue(self::FILE_PERMISSION_PLACEHOLDER, (int)config('streamline.file_permissions'))
            ->setStubValue(self::RETAIN_OLD_RELEASE_PLACEHOLDER, (bool)config('streamline.retain_old_releases'));
        return $this;
    }

    private function setStubValue(string $placeholder, string|int|bool|array $value): self
    {
        $value = self::stringify($value);

        $this->configClassStubContents = str_replace($placeholder, $value, $this->configClassStubContents);
        return $this;
    }

    public static function stringify(int|bool|array|string $value): string
    {
        if (!is_array($value)) {
            return (string)$value;
        }

        return preg_replace(
            pattern: [
                '/\s+/',                     // Remove multiple whitespaces
                '/array\s*\(\s*(.*),\s*\)/', // Clean up array() syntax
                '/\d+\s*=>\s*/',             // Remove numeric indexes
                '/,\s+/',                         // Remove space after comma
            ],
            replacement: [
                ' ',    // Replace multiple spaces with single space
                '[$1]', // Compact array declaration
                '',     // Remove numeric indexes
                ',',     // Replace space after comma with comma
            ],
            subject: var_export(array_values($value), true)
        );
    }
}
