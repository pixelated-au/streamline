<?php

/** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Actions;

use Closure;
use Illuminate\Container\Attributes\Config as ConfigAttribute;
use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Factories;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

class InstantiateStreamlineUpdater
{
    private const int OUTPUT_TAIL_LENGTH = 4000;

    public function __construct(
        private readonly Factories\ProcessFactory $process,
        #[ConfigAttribute('streamline.runner_class')]
        /** @var class-string */
        private readonly string $runnerClass,
    ) {}

    /**
     * @param  Closure(string, string): void  $callback
     */
    public function execute(
        string $versionToInstall,
        string $composerPath,
        Closure $callback,
        ?string $backupArchivePath = null,
    ): void {
        $classFilePath = $this->getClassFilePath();

        $script         = sprintf('require "%s"; (new %s)->run();', $classFilePath, $this->runnerClass);
        $protectedPaths = $this->parseArray([
            ...Config::commaToArray('streamline.protected_files'),
        ]);
        $carryForwardPaths = $this->parseArray([
            ...Config::commaToArray('streamline.carry_forward_paths'),
        ]);

        $output = '';

        $exitCode = $this->process
            ->invoke($script)
            ->setEnv($this->stringifyEnv([
                'TEMP_DIR'                  => config('streamline.work_temp_dir'),
                'LARAVEL_BASE_PATH'         => base_path(),
                'PUBLIC_DIR_NAME'           => public_path(),
                'FRONT_END_BUILD_DIR'       => config('streamline.laravel_build_dir_name'),
                'INSTALLING_VERSION'        => $versionToInstall,
                'COMPOSER_PATH'             => $composerPath,
                'PROTECTED_PATHS'           => $protectedPaths,
                'CARRY_FORWARD_PATHS'       => $carryForwardPaths,
                'DIR_PERMISSION'            => $this->parsePermission(config('streamline.directory_permissions'), 0755),
                'FILE_PERMISSION'           => $this->parsePermission(config('streamline.file_permissions'), 0644),
                'PROTECTED_FILE_PERMISSION' => $this->parsePermission(config('streamline.protected_file_permissions'), 0600),
                // The specific archive that BackupCurrentInstallation wrote, not the directory it
                // lives in - the filename is timestamped and the updater has to delete that file.
                'OLD_RELEASE_ARCHIVE_PATH' => (string) $backupArchivePath,
                'DO_RETAIN_OLD_RELEASE'    => (bool) config('streamline.retain_old_releases'),
                'IS_TESTING'               => defined('IS_TESTING'), // Set in phpunit config XML file.
            ]))->run(function(string $type, string $buffer) use ($callback, &$output): void {
                // Only the tail is kept: it is what explains a failure, and the output is unbounded.
                $output = substr($output . $buffer, -self::OUTPUT_TAIL_LENGTH);
                $callback($type, $buffer);
            });

        // The exit code is the only reliable success signal. PHP prints fatal errors to stdout
        // (display_errors=1) and a harmless warning can reach stderr, so neither stream decides it.
        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Error: The updater process failed with exit code $exitCode." .
                (trim($output) !== '' ? "\nOutput:\n" . trim($output) : '')
            );
        }
    }

    /**
     * Symfony discards any environment value that is exactly false rather than passing it as a
     * string. Left as booleans, DO_RETAIN_OLD_RELEASE simply vanished whenever it was false, and
     * the updater aborted reporting it as unset - so turning retain_old_releases off broke every
     * update. Everything is handed over as a string instead.
     *
     * @param  array<string, mixed>  $env
     * @return array<string, string>
     */
    protected function stringifyEnv(array $env): array
    {
        return array_map(
            static fn(mixed $value): string => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            $env
        );
    }

    /**
     * @param  string[]|string  $input
     */
    protected function parseArray(array|string $input): string
    {
        if (is_array($input)) {
            $this->assertPathsAreContained($input);

            // json_encode() rather than manual quoting: a path containing a quote or a backslash
            // would otherwise produce invalid JSON, and the updater would silently lose the
            // protected paths it is supposed to preserve.
            return json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return $input;
    }

    /**
     * Protected paths are copied relative to the installation root, so a traversal segment or an
     * absolute path would write outside the deployment.
     *
     * @param  string[]  $paths
     */
    protected function assertPathsAreContained(array $paths): void
    {
        foreach ($paths as $path) {
            // '', '.' and './' all resolve to the installation root itself and would copy the whole
            // old release over the new one.
            if (preg_match('#^(\.?[\\\\/]+)*\.?$#', trim($path))) {
                throw new RuntimeException(
                    "Error: The protected path '$path' is empty or refers to the installation root itself."
                );
            }

            if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path)) {
                throw new RuntimeException(
                    "Error: The protected path '$path' must be relative to the installation root and " .
                    'cannot contain ".." segments.'
                );
            }
        }
    }

    /**
     * Permissions are octal. Read from the environment they arrive as strings, where a plain
     * (int) cast turns "0644" into the decimal 644 - which is the mode 01204, not 0644.
     */
    protected function parsePermission(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(?:0o?)?[0-7]{3,4}$/i', trim($value))) {
            return (int) octdec(ltrim(strtolower(trim($value)), '0o') ?: '0');
        }

        return $default;
    }

    /**
     * The runner class name is interpolated into a PHP snippet that is executed with `php -r`, so
     * it is restricted to the characters a class name can legally contain. That leaves no quote,
     * semicolon, space or parenthesis available to break out of the snippet.
     */
    protected function isPlainClassName(string $class): bool
    {
        $permittedCharacters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_\\';

        return $class !== '' && strspn($class, $permittedCharacters) === strlen($class);
    }

    protected function getClassFilePath(): string
    {
        if (!$this->isPlainClassName($this->runnerClass)) {
            throw new RuntimeException("Error: The configured runner class '$this->runnerClass' is not a valid class name.");
        }

        try {
            return (new ReflectionClass($this->runnerClass))->getFileName();
        } catch (ReflectionException $e) {
            throw new RuntimeException("Error instantiating updater class '$this->runnerClass': " . $e->getMessage());
        }
    }
}
