<?php

namespace Pixelated\Streamline\Tests;

use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected vfsStreamDirectory $rootFs;

    protected vfsStreamDirectory $deploymentDir;

    protected string $rootPath;

    protected string $deploymentPath;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function getEnvironmentSetUp($app): void {}

    public function deploymentPath(): string
    {
        return $this->deploymentPath;
    }
}
