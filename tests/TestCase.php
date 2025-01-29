<?php

namespace Pixelated\Streamline\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
//    use InteractsWithContainer;
//    use WithWorkbench;

    protected vfsStreamDirectory $rootFs;
    protected vfsStreamDirectory $deploymentDir;
    protected string $rootPath;
    protected string $deploymentPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!file_exists('./workbench/.env')) {
            symlink('.env', './workbench/.env');
        }
    }

    public function getEnvironmentSetUp($app): void
    {
    }

    public function deploymentPath(): string
    {
        return $this->deploymentPath;
    }
}
