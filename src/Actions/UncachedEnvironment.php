<?php

namespace Pixelated\Streamline\Actions;

use Dotenv\Dotenv;

/** @codeCoverageIgnore */
class UncachedEnvironment
{
    public function get($key, $default = null): string
    {
        $currentEnv = Dotenv::createArrayBacked(base_path())->load();

        // todo delete when finished with this...
        logger('Current environment variables:');
        logger(print_r($currentEnv, true));

        return isset($currentEnv[$key]) ?: $default;
    }
}
