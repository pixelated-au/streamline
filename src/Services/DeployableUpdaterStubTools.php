<?php /** @noinspection PhpClassCanBeReadonlyInspection */

namespace Pixelated\Streamline\Services;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class DeployableUpdaterStubTools
{
    public function __construct(
        #[Config('streamline.updater_deploy_to_path')]
        private readonly string $updaterPath,
        #[Config('streamline.updater_file_prefix')]
        private readonly string $updaterFilePrefix,
    )
    {
        if (empty($this->updaterPath)) {
            throw new RuntimeException('Updater path cannot be empty. It can be set using the config');
        }
    }


    public function confirmUpdateNotRunning(): void
    {
        if (!empty($this->updaterPath)) {
            $updateFiles = File::glob("$this->updaterPath/{$this->updaterFilePrefix}_*.php");
            if (!empty($updateFiles)) {
                $existing = implode(',', $updateFiles);
                throw new RuntimeException("Updater already appears to be running. If this is not intentional, please delete: $existing");
            }
        }
    }

    public function getUpdatePhpFileStub(): string
    {
        return File::get(__DIR__ . '/stubs/streamline.php.stub');
    }

    public function getConfigClassStub(): string
    {
        return File::get(__DIR__ . '/stubs/StreamlineConfig.stub');
    }

    public function generateUpdaterFileDeploymentPath(): string
    {
        return $this->updaterPath . '/' . $this->updaterFilePrefix . '_' . Str::random(20) . '.php';
    }
}
