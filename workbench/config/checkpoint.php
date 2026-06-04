<?php

use Checkpoint\Checks;

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled Checks
    |--------------------------------------------------------------------------
    |
    | Every default check is listed here and enabled by default. Set any
    | entry to `false` to exclude it from `php artisan checkpoint:scan`.
    |
    | Checks not listed in this map fall back to enabled — so when you
    | upgrade Checkpoint and new checks are added, you keep the protection
    | without re-publishing this file.
    |
    */

    'checks' => [
        Checks\ComposerAuditCheck::class            => true,
        Checks\NpmAuditCheck::class                 => false, // No npm installed
        Checks\EnvironmentCheck::class              => false, // Unused in a package
        Checks\GitIgnoreCheck::class                => false, // false positive due to the Orchestra framework
        Checks\FilePermissionsCheck::class          => false, // not needed in this case
        Checks\HardcodedSecretsCheck::class         => true,
        Checks\SqlInjectionCheck::class             => true,
        Checks\MassAssignmentCheck::class           => true,
        Checks\XssCheck::class                      => true,
        Checks\CsrfCheck::class                     => true,
        Checks\OpenRedirectCheck::class             => true,
        Checks\CommandInjectionCheck::class         => true,
        Checks\InsecureDeserializationCheck::class  => true,
        Checks\DebugFunctionsCheck::class           => true,
        Checks\SensitiveExposureCheck::class        => true,
        Checks\SsrfCheck::class                     => true,
        Checks\TlsVerificationCheck::class          => true,
        Checks\CorsConfigCheck::class               => false, // no requests being made
        Checks\PackageFreshnessCheck::class         => true,
        Checks\SuspiciousVendorAutoloadCheck::class => true,
        Checks\SupplyChainToolingCheck::class       => true,
        Checks\PathTraversalCheck::class            => true,
        Checks\WeakCryptographyCheck::class         => true,
        Checks\InsecureRngCheck::class              => true,
        Checks\SessionSecurityCheck::class          => true,
        Checks\EolVersionCheck::class               => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Freshness (Supply Chain)
    |--------------------------------------------------------------------------
    |
    | Composer packages released within `minimum_age_days` will fail the
    | "Package Freshness" check. This mitigates supply-chain attacks that
    | typically get caught and removed from Packagist within hours or days.
    |
    | Add fully-qualified package names to `whitelist` to bypass the age
    | check for specific dependencies (e.g. a critical security patch you
    | need to deploy before the freshness window expires).
    |
    */

    'package_freshness' => [
        'minimum_age_days' => 3,
        'whitelist'        => [
            // Checkpoint exempts itself from the freshness gate so a fresh
            // release of the scanner cannot block its own user's deploy.
            'andreapollastri/checkpoint',
            'roave/security-advisories',
            // 'vendor/package',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspicious Vendor Autoload
    |--------------------------------------------------------------------------
    |
    | The "Suspicious Vendor Autoload" check warns when a package under
    | vendor/ registers PHP files via `autoload.files` — the exact mechanism
    | abused by the May 2026 Laravel-Lang supply-chain attack to execute
    | code on every request.
    |
    | A baked-in whitelist already covers packages that legitimately use
    | this mechanism (laravel/framework, symfony/polyfill-*, guzzlehttp/*,
    | ramsey/uuid, …). Add your own trusted entries below — exact matches
    | or `vendor/*` wildcards are both supported.
    |
    */

    'suspicious_autoload' => [
        'whitelist' => [
            'laravel/prompts',
            'mockery/mockery',
            'myclabs/deep-copy',
            'nunomaduro/collision',
            'nunomaduro/termwind',
            'orchestra/sidekick',
            'orchestra/testbench-core',
            'pestphp/pest',
            'pestphp/pest-plugin-arch',
            'pestphp/pest-plugin-laravel',
            'php-di/php-di',
            'php-mock/php-mock',
            'php-mock/php-mock-integration',
            'phpunit/phpunit',
            'psy/psysh',
            'ralouphie/getallheaders',
            'react/promise',
            'sebastian/global-state',
            'sebastian/type',
            'spatie/ray',
            'symfony/clock',
            'symfony/string',
            'symfony/translation',
            'symfony/var-dumper',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suppressed Findings
    |--------------------------------------------------------------------------
    |
    | Add 12-character finding hashes here to silence specific FAIL/WARN
    | issues you have intentionally accepted (false positive, legacy code,
    | etc.). Hashes are shown in square brackets next to each finding when
    | you run the scan — copy the bracketed value into this array.
    |
    | The hash is content-stable: refactors that only shift line numbers
    | will not invalidate it.
    |
    | If every finding of a check is suppressed, the check is downgraded to
    | PASS with an explicit "N suppressed" message.
    |
    */

    'suppressed' => [
        'c14c8f8c91e7', // guzzlehttp/guzzle <= Added 4 Jun 2026, delete after 8 Jun 2026
        '7441e0dfd2ad', // guzzlehttp/promises <= Added 4 Jun 2026, delete after 8 Jun 2026
        '22b7ef123a7a', // guzzlehttp/psr7 <= Added 4 Jun 2026, delete after 8 Jun 2026
        '50718a7e8f05', // phpunit/php-code-coverage <= Added 4 Jun 2026, delete after 8 Jun 2026
        'c79dbf073eab', // sebastian/global-state <= Added 4 Jun 2026, delete after 8 Jun 2026
    ],
];
