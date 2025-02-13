<?php

namespace Pixelated\Streamline\Tests;

use Illuminate\Foundation\Testing\Concerns\InteractsWithContainer;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use org\bovigo\vfs\vfsStreamDirectory;
use Pixelated\Streamline\StreamlineServiceProvider;
use Spatie\LaravelRay\RayServiceProvider;

class LaravelTestCase extends Orchestra
{
    use InteractsWithContainer;
    use WithWorkbench;

    protected vfsStreamDirectory $rootFs;

    protected vfsStreamDirectory $deploymentDir;

    protected string $rootPath;

    protected string $deploymentPath;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            StreamlineServiceProvider::class,
            RayServiceProvider::class,
        ];
    }

    public function deploymentPath(): string
    {
        return $this->deploymentPath;
    }
}
