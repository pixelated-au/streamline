<?php

namespace Pixelated\Streamline\Tests\Feature\Traits;

use Illuminate\Support\Facades\Cache;
use Pixelated\Streamline\Enums\CacheKeysEnum;

trait CheckCommandCommon
{
    protected ?string $_nextAvailableVersion = 'v2.8.7b';

    protected ?array $_availableVersions = ['v2.8.7b', 'foo', 'bar'];

    /**
     * @param array{
     *     nextAvailableVersion: string,
     *     availableVersions: string[],
     * } $options
     */
    public function setDefaults(array $options): self
    {
        foreach ($options as $key => $option) {
            // check to see if the variable exists on this instance and then set the default value
            if (property_exists($this, "_$key")) {
                $key        = "_$key";
                $this->$key = $option;
            }
        }

        return $this;
    }

    public function mockCache(): self
    {
        Cache::shouldReceive('get')
            ->with(CacheKeysEnum::NEXT_AVAILABLE_VERSION->value)
            ->andReturn($this->_nextAvailableVersion);

        Cache::shouldReceive('forever')->withAnyArgs();

        Cache::shouldReceive('get')
            ->with(CacheKeysEnum::AVAILABLE_VERSIONS->value)
            ->andReturn($this->_availableVersions);

        return $this;
    }
}
