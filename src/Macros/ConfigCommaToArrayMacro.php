<?php

namespace Pixelated\Streamline\Macros;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

/**
 * @mixin \Illuminate\Support\Facades\Config
 */
class ConfigCommaToArrayMacro
{
    public static function register(): void
    {
        Config::macro(
            name: 'commaToArray',
            macro: function (array|string $key, mixed $default = null): array {
                // @phpstan-ignore variable.undefined
                $result = $this->get($key, $default);
                if (is_string($result)) {
                    return Arr::map(explode(',', $result), static fn ($value) => trim($value));
                }

                throw_if(!is_array($result), exception: 'Invalid value (' . var_export($result, true) . ") for $key. Expected a comma-separated list or an array.");

                return $result;
            });
    }
}
