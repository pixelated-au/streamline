<?php

namespace Pixelated\Streamline\Tests\Updater\Feature\Filters;

use php_user_filter;

class StreamlineConfigPlaceholderFilter extends php_user_filter
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
        return str_replace(
            [
                '{{ BASE_PATH }}',
                '{{ HASH }}',
                '{{ SOURCE_DIR }}',
                '{{ FRONT_END_BUILD_DIR }}',
                '{{ PUBLIC_DIR_NAME }}',
                '{{ TEMP_DIR }}',
                '{{ INSTALLING_VERSION }}',
                '{{ BACKUP_DIR }}',
                '{{ ALLOWED_FILE_EXTENSIONS }}',
                '{{ PROTECTED_PATHS }}',
                '{{ MAX_FILE_SIZE }}',
                '{{ DIR_PERMISSION }}',
                '{{ FILE_PERMISSION }}',
                '{{ RETAIN_OLD_RELEASE }}'
            ],
            [
                '/path/to/base',
                '1234567890abcdef',
                '/path/to/source',
                'build',
                'public',
                '/path/to/temp',
                'v1.0.0',
                '/path/to/backup',
                "['txt','jpg']",
                "['/protected/path']",
                10,
                0755,
                0644,
                true
            ],
            $bucket->data
        );
    }
}
