<?php

namespace Pixelated\Streamline\Actions;

use Dotenv\Dotenv;

/** @codeCoverageIgnore */
class UncachedEnvironment
{
    public function get(string $key, ?string $default = null): ?string
    {
        $currentEnv = Dotenv::createArrayBacked(base_path())->load();

        return $currentEnv[$key] ?? $default;
    }
}
