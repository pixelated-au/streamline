<?php

namespace Pixelated\Streamline\Tests\Feature\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use Pixelated\Streamline\Actions\GetAvailableVersions;
use Pixelated\Streamline\Enums\CacheKeysEnum;
use ZipArchive;

trait UpdateCommandCommon
{
    /**
     * @param array{
     *     stubContent: string,
     *     deployPath: string,
     *     cachedVersionToInstall: string|null,
     *     requestedVersionToInstallByUser: string|null,
     *     doForceUpdate: bool,
     * } $options
     *
     * @deprecated
     */
    public function setDefaults(array $options): self
    {
        foreach ($options as $key => $option) {
            // check to see if the variable exists on this instance and then set the default value
            if (property_exists($this, "_$key")) {
                $key = "_$key";
                $this->$key = $option;
            }
        }

        return $this;
    }

    public function mockFile(): self
    {
        File::shouldReceive('exists')->andReturn(true, true, false);
        File::shouldReceive('isDirectory')->andReturnTrue();
        File::shouldReceive('isWritable')->andReturnTrue();
        File::shouldReceive('isReadable')->andReturnTrue();
        File::shouldReceive('put');
        File::shouldReceive('dirname')->andReturnUsing(fn (string $value) => dirname($value));
        File::shouldReceive('name')->andReturnUsing(fn (string $value) => pathinfo($value, PATHINFO_FILENAME));

        return $this;
    }

    public function mockProcess(): self
    {
        Process::shouldReceive('path')->andReturnSelf();
        Process::shouldReceive('env')->andReturnSelf();
        Process::shouldReceive('run')->withSomeOfArgs(['php', base_path('.env')]);

        return $this;
    }

    public function mockCache(?array $availableVersions = null, ?string $cachedVersionToInstall = null): self
    {
        Cache::shouldReceive('forever')->withSomeOfArgs(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value);
        Cache::shouldReceive('get')->withSomeOfArgs(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value)->andReturn($cachedVersionToInstall);
        Cache::shouldReceive('forever')->withSomeOfArgs(CacheKeysEnum::AVAILABLE_VERSIONS->value);

        Cache::shouldReceive('get')
            ->withSomeOfArgs(CacheKeysEnum::AVAILABLE_VERSIONS->value)
            ->andReturn(collect($availableVersions ?? ['v0.0.0']));

        return $this;
    }

    public function mockGetAvailableVersions(string $returnedVersion = '"test version"'): self
    {
        test()->mock(
            GetAvailableVersions::class,
            fn (MockInterface $mock) => $mock->shouldReceive('execute')->andReturn($returnedVersion)
        );

        return $this;
    }

    public function mockZipArchive(): self
    {
        test()->mock(ZipArchive::class, function (MockInterface $mock) {
            $mock->shouldReceive('open')->andReturn(true);
            $mock->shouldReceive('extractTo')->andReturn(true);
            $mock->shouldReceive('close')->andReturn(true);
        });

        return $this;
    }
}
