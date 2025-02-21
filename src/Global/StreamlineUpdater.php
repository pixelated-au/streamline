<?php

declare(strict_types=1);

use Pixelated\Streamline\Updater\RunCompleteGitHubVersionRelease;

class StreamlineUpdater
{
    public string $laravelBasePath;

    public string $publicDirName;

    public string $frontEndBuildDir;

    public string $tempDir;

    public string $installingVersion;

    public string $oldReleaseArchivePath;

    public array $protectedPaths;

    public int $dirPermission;

    public int $filePermission;

    public bool $doRetainOldRelease;

    public int $isTesting = self::TESTING_OFF;

    private array $envIssues = [];

    public const int TESTING_OFF = 0;

    public const int TESTING_ON = 1;

    public const int TESTING_SKIP_REQUIRE_AUTOLOAD = 2;

    /**
     * @throws \JsonException
     */
    public function __construct()
    {
        $this->tempDir = $this->env('TEMP_DIR');
        $this->laravelBasePath = $this->env('LARAVEL_BASE_PATH');
        $this->publicDirName = $this->env('PUBLIC_DIR_NAME');
        $this->frontEndBuildDir = $this->env('FRONT_END_BUILD_DIR');
        $this->installingVersion = $this->env('INSTALLING_VERSION');
        $this->protectedPaths = $this->jsonEnv('PROTECTED_PATHS');
        $this->dirPermission = (int) $this->env('DIR_PERMISSION');
        $this->filePermission = (int) $this->env('FILE_PERMISSION');
        $this->oldReleaseArchivePath = $this->env('OLD_RELEASE_ARCHIVE_PATH');
        $this->doRetainOldRelease = (bool) $this->env('DO_RETAIN_OLD_RELEASE');
        $this->isTesting = (int) (getenv('IS_TESTING') ?: self::TESTING_OFF);

        if (count($this->envIssues)) {
            throw new InvalidArgumentException(implode("\n", $this->envIssues));
        }

        if ($this->isTesting > self::TESTING_OFF && ($this->isTesting - self::TESTING_ON) & ($this->isTesting - self::TESTING_ON - 1)) {
            // @codeCoverageIgnoreStart
            throw new InvalidArgumentException('IS_TESTING environment variable must be a class constant');
            // @codeCoverageIgnoreEnd
        }

        if ($this->isTesting & self::TESTING_SKIP_REQUIRE_AUTOLOAD) {
            return;
        }

        require $this->autoloadFile();
    }

    private function env(string $name): string
    {
        $val = getenv($name, true);
        if (!$val) {
            $this->envIssues[] = "Environment variable $name needs to be set!";
        }

        return (string) $val;
    }

    /** @noinspection PhpSameParameterValueInspection */
    private function jsonEnv(string $name): array
    {
        $env = $this->env($name);

        $val = [];
        try {
            $val = json_decode($env, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            try {
                $val = json_decode("[$env]", true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->envIssues[] = "Environment variable $name=$env cannot be converted to an array. Pass in a JSON compatible array string!";
            }
        }

        return $val;
    }

    public function run(): void
    {
        $updater = new RunCompleteGitHubVersionRelease(
            tempDirName: $this->tempDir,
            laravelBasePath: $this->laravelBasePath,
            publicDirName: $this->publicDirName,
            frontendBuildDir: $this->frontEndBuildDir,
            installingVersion: $this->installingVersion,
            protectedPaths: $this->protectedPaths,
            dirPermission: $this->dirPermission,
            filePermission: $this->filePermission,
            oldReleaseArchivePath: $this->oldReleaseArchivePath,
            doRetainOldReleaseDir: $this->doRetainOldRelease,
            doOutput: true,
        );
        // @codeCoverageIgnoreStart
        if ($this->isTesting & self::TESTING_ON) {
            return;
        }
        $updater->run();
        // @codeCoverageIgnoreEnd
    }

    public function autoloadFile(): string
    {
        $composerJsonPath = rtrim($this->laravelBasePath, '/') . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            throw new RuntimeException("Cannot locate the base composer file ($composerJsonPath) for autoloading");
        }

        try {
            $composer = json_decode(file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);
            $dir = $composer['config']['vendor-dir'] ?? 'vendor';

            return "$dir/autoload.php";
        } catch (JsonException) {
            throw new RuntimeException("The file $this->laravelBasePath/composer.json file contains invalid JSON");
        }
    }
}
