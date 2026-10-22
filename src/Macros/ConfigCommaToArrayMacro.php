<?php

namespace Pixelated\Streamline\Macros;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

/**
 * @mixin Config
 */
class ConfigCommaToArrayMacro
{
    public static function register(): void
    {
        Config::macro(
            name: 'commaToArray',
            macro: function(array|string $key, mixed $default = null): array {
                $result = $this->get($key, $default);

                if (is_string($result)) {
                    $items = explode(',', $result);

                    // A JSON array in the environment, e.g. STREAMLINE_PROTECTED_PATHS='[".env"]'.
                    if (str_starts_with(trim($result), '[')) {
                        $decoded = json_decode($result, true);

                        if (is_array($decoded)) {
                            throw_if(
                                !array_is_list($decoded) || count(array_filter($decoded, 'is_string')) !== count($decoded),
                                exception: "Invalid JSON list for $key. Expected an array of strings."
                            );
                            $items = $decoded;
                        }
                    }

                    // Empty entries (a trailing comma, ",,") are dropped rather than returned as ''.
                    return array_values(array_filter(
                        Arr::map($items, static fn($value) => trim($value)),
                        static fn($value) => $value !== ''
                    ));
                }

                throw_if(!is_array($result), exception: 'Invalid value (' . var_export($result, true) . ") for $key. Expected a comma-separated list or an array.");

                return $result;
            }
        );
    }
}
