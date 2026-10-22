<?php

namespace Pixelated\Streamline\Enums;

enum CacheKeysEnum: string
{
    case AVAILABLE_VERSIONS     = 'streamline_available_versions';
    case PRE_RELEASE_VERSIONS   = 'streamline_pre_release_versions';
    case NEXT_AVAILABLE_VERSION = 'streamline_next_available_version';
    case INSTALLED_VERSION      = 'streamline_installed_version';
}
