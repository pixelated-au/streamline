<?php

namespace Pixelated\Streamline\Tests\Updater\Feature\Filters;

use php_user_filter;

class StreamlineStubPlaceholderFilter extends php_user_filter
{
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = $this->doReplace($bucket);
            $consumed     += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }

    private function doReplace(object $bucket): string|array
    {
        return str_replace('//{{ STREAMLINE_CONFIG_CLASS }}', $this->params, $bucket->data);
    }
}
