<?php

/** @noinspection PhpUnusedParameterInspection */

use Pixelated\Streamline\Pipes\BackupCurrentInstallation;
use Pixelated\Streamline\Pipes\CheckComposer;
use Pixelated\Streamline\Pipes\CheckLaravelBasePathWritable;
use Pixelated\Streamline\Pipes\DownloadRelease;
use Pixelated\Streamline\Pipes\GetNextAvailableReleaseVersion;
use Pixelated\Streamline\Pipes\MakeTempDir;
use Pixelated\Streamline\Pipes\RunUpdate;
use Pixelated\Streamline\Pipes\UnpackRelease;
use Pixelated\Streamline\Pipes\VerifyReleaseChecksum;
use Pixelated\Streamline\Pipes\VerifyReleaseSignature;
use Pixelated\Streamline\Pipes\VerifyVersion;
use Pixelated\Streamline\Pipes\WarnUnverifiedRelease;
use Pixelated\Streamline\Services\FinishUpdate;
use Symfony\Component\Process\Process;

return [
    /*
    |--------------------------------------------------------------------------
    | Currently Installed Version
    |--------------------------------------------------------------------------
    |
    | The currently installed version of the application.
    |
    */

    'installed_version' => env('STREAMLINE_APPLICATION_VERSION_INSTALLED', 'v0.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Protected Files
    |--------------------------------------------------------------------------
    |
    | The paths listed here (either an array or comma separated list) will
    | *not* be overwritten or deleted during the update process.
    | The paths should be relative to your root installation directory.
    |
    | Any files in the root directory should just be the file name and not
    | include the root directory prefix. For example:
    |  - Good: .env
    |  - Bad: /.env
    |
    | A JSON array is also accepted from the environment. Empty entries are
    | ignored; '.', absolute paths and '..' segments are rejected.
    |
    */

    'protected_files' => env('STREAMLINE_PROTECTED_PATHS', ['.env', 'storage/logs/streamline.log']),

    /*
    |--------------------------------------------------------------------------
    | Carried Forward Paths
    |--------------------------------------------------------------------------
    |
    | Paths (either an array or a comma separated list) copied out of the old
    | release into the new one, relative to your root installation directory.
    | Use this for content the application itself writes and the release archive
    | does not ship - user uploads, generated images, and so on.
    |
    | Unlike `protected_files`, a file already present in the new release is
    | never overwritten: the incoming release always wins. That makes this safe
    | for directories the release also contains, and it is why `public/build`
    | (which is carried forward automatically) works the way it does.
    |
    | Files carried across are given `file_permissions`, not the tighter
    | `protected_file_permissions`, because they are usually served publicly.
    |
    | Example:
    |   STREAMLINE_CARRY_FORWARD_PATHS=public/uploads,public/images,storage/app/public
    |
    | NOTE: do not list `public` itself. The new release ships its own index.php
    | and build assets, and while they would not be overwritten, every stale
    | asset from the old release would be dragged forward for ever.
    |
    */

    'carry_forward_paths' => env('STREAMLINE_CARRY_FORWARD_PATHS', []),

    /*
    |--------------------------------------------------------------------------
    | GitHub Repository Name
    |--------------------------------------------------------------------------
    |
    | GitHub Repo in the format of account/repository where the release
    | archives will come from.
    |
    */

    'github_repo' => env('STREAMLINE_GITHUB_RELEASE_REPOSITORY'),

    /*
    |--------------------------------------------------------------------------
    | GitHub Auth Token
    |--------------------------------------------------------------------------
    |
    | This is optional. If you want to download releases from private
    | repositories, or are being rate limited, you need to generate a personal
    | access token.
    | See: https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens
    |
    */

    'github_auth_token' => env('STREAMLINE_GITHUB_AUTH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | GitHub API Pagination Limit
    |--------------------------------------------------------------------------
    |
    | Max 100 - as specified by GitHub API. If you set it to 100, you may run
    | into memory issues.
    |
    */

    'github_api_pagination_limit' => env('STREAMLINE_GITHUB_API_PAGINATION_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Release Archive File Name
    |--------------------------------------------------------------------------
    |
    | The name of the file that will be downloaded from the GitHub repo such as
    | "release.zip" or "release.tar.gz"
    |
    */

    'release_archive_file_name' => env('STREAMLINE_GITHUB_RELEASE_ARCHIVE_FILENAME', 'release.zip'),

    /*
    |--------------------------------------------------------------------------
    | Release Archive Checksum Algorithm
    |--------------------------------------------------------------------------
    |
    | Before the downloaded archive is unpacked over your installation, it is
    | checked against a checksum file published alongside it in the same
    | release. For an archive named "release.zip" and the default sha256
    | algorithm, that file is "release.zip.sha256". It may contain either a
    | bare digest or the standard `shasum` output format.
    |
    | NOTE: a checksum published in the same release only proves the download
    | arrived intact. It does *not* prove the release is genuine - anyone able
    | to replace the archive can replace the checksum alongside it. Proving
    | authenticity requires a signature verified against a key you hold.
    |
    */

    'release_checksum_algorithm' => env('STREAMLINE_RELEASE_CHECKSUM_ALGORITHM', 'sha256'),

    /*
    |--------------------------------------------------------------------------
    | Require A Release Checksum
    |--------------------------------------------------------------------------
    |
    | When true, an update is aborted if the checksum file cannot be retrieved.
    | When false, a warning is emitted and the update continues unverified.
    |
    | This defaults to false so that existing releases which do not publish a
    | checksum file keep working. Set it to true once your release process
    | publishes one - a mismatched archive always aborts the update either way.
    |
    */

    'require_release_checksum' => env('STREAMLINE_REQUIRE_RELEASE_CHECKSUM', false),

    /*
    |--------------------------------------------------------------------------
    | Release Public Key
    |--------------------------------------------------------------------------
    |
    | The PEM-encoded public key used to prove a release is genuine, given
    | either as the PEM itself or as a path to a file holding it. This is the
    | trust anchor: unlike the checksum above, it lives on *this* machine and
    | not in the release, so an attacker able to publish a release still cannot
    | produce a signature that satisfies it.
    |
    | What gets signed is the checksum file, not the archive. The key vouches
    | for the digest and the digest vouches for the archive, which keeps the
    | guarantee intact without ever loading the whole archive into memory.
    |
    | Generate a key pair with:
    |   openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 \
    |       -out streamline_release.key
    |   openssl pkey -in streamline_release.key -pubout -out streamline_release.pub
    |
    | Use an RSA or EC key. Ed25519 keys are rejected by PHP's openssl_verify()
    | no matter how the signature was produced.
    |
    | Leave this empty to skip signature verification entirely.
    |
    */

    'release_public_key' => env('STREAMLINE_RELEASE_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Require A Release Signature
    |--------------------------------------------------------------------------
    |
    | When true, an update is aborted unless the release publishes a signature
    | that verifies against the public key above. When false, a missing
    | signature only produces a warning and the update continues.
    |
    | An *invalid* signature always aborts the update, whichever way this is
    | set. What this controls is whether an unsigned release is acceptable.
    |
    | This defaults to false so that installations upgrading to this version of
    | Streamline keep working. While it is off, every update prints a banner
    | saying so - see docs/verifying-releases.md.
    |
    */

    'require_release_signature' => env('STREAMLINE_REQUIRE_RELEASE_SIGNATURE', false),

    /*
    |--------------------------------------------------------------------------
    | Release Signature File Extension
    |--------------------------------------------------------------------------
    |
    | The signature is looked for next to the checksum file, under its name plus
    | this extension. With the defaults that is "release.zip.sha256.sig".
    |
    */

    'release_signature_file_extension' => env('STREAMLINE_RELEASE_SIGNATURE_FILE_EXTENSION', 'sig'),

    /*
    |--------------------------------------------------------------------------
    | Temporary Work Directory
    |--------------------------------------------------------------------------
    |
    | The temporary directory used to extract and process the release archive
    | It will be located from your root installation directory. Eg:
    | app_root/.streamline_tmp
    | It must be outside the installation directory (it is rejected otherwise).
    |
    */

    'work_temp_dir' => env('STREAMLINE_WORK_TEMP_DIR', dirname(base_path()) . '/.streamline_tmp'),

    /*
    |--------------------------------------------------------------------------
    | Backup Directory
    |--------------------------------------------------------------------------
    |
    | This is the directory where backups of the previous release will be
    | stored. If backups are not retained, the backup will be removed.
    | It must be outside the installation directory (it is rejected otherwise).
    |
    */

    'backup_dir' => env('STREAMLINE_BACKUP_DIR', dirname(base_path()) . '/streamline_backups'),

    /*
    |--------------------------------------------------------------------------
    | Updater Script Filename Prefix
    |--------------------------------------------------------------------------
    |
    | For security purposes, when the updater file is deployed, it's given a
    | random name. The prefix is so you can identify what file it is
    | The underscore is not needed. Eg if a script has the prefix 'updater',
    | the deployed file might look like this: updater_4rls8908sre43df4lu.php
    |
    */

    'updater_file_prefix' => env('STREAMLINE_UPDATER_FILE_PREFIX', 'streamline'),

    /*
    |--------------------------------------------------------------------------
    | Web Asset Public Disk Name
    |--------------------------------------------------------------------------
    |
    | This is the root disk name where the web assets (js, css, images) will be
    | stored. Typically, it's [APP_ROOT]/public.
    |
    */

    'laravel_public_disk_name' => env('STREAMLINE_WEB_ASSET_PUBLIC_DISK_NAME', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Deployment Build Directory
    |--------------------------------------------------------------------------
    |
    | It is assumed that the project has assets that are managed by a manifest
    | The directory where the JS front-end build manifest file.Eg manifest.json.
    | This is where that file is located withing the project.
    |
    */

    'laravel_build_dir_name' => env('STREAMLINE_WEB_ASSET_BUILD_DIR_NAME', 'build'),

    /*
    |--------------------------------------------------------------------------
    | Asset Files Directory Name
    |--------------------------------------------------------------------------
    |
    | The directory name under the `laravel_build_dir` where the
    | generated/built assets (js, css) are stored
    |
    */

    'laravel_asset_dir_name' => 'assets',

    /*
    |--------------------------------------------------------------------------
    | Default Directory Permissions
    |--------------------------------------------------------------------------
    |
    | After running the updater script, the permissions of the directory will
    | be set to this value. Values are octal, and may be given in the .env file
    | in the usual form, eg STREAMLINE_DIRECTORY_PERMISSIONS=0755
    |
    */

    'directory_permissions' => env('STREAMLINE_DIRECTORY_PERMISSIONS', 0755),

    /*
    |--------------------------------------------------------------------------
    | Default File Permissions
    |--------------------------------------------------------------------------
    |
    | The permissions given to the files the updater itself copies into the new
    | release: the previous front-end build assets and anything in
    | `carry_forward_paths`. Files that ship in the release archive keep the
    | permissions they were extracted with, and `protected_files` use
    | `protected_file_permissions` instead. Values are octal, and may be given in
    | the .env file in the usual form, eg STREAMLINE_FILE_PERMISSIONS=0644
    |
    */

    'file_permissions' => env('STREAMLINE_FILE_PERMISSIONS', 0644),

    /*
    |--------------------------------------------------------------------------
    | Protected File Permissions
    |--------------------------------------------------------------------------
    |
    | The permissions applied to the files listed in `protected_files` when they
    | are carried across into the new release. These are kept separate from
    | `file_permissions` because protected files are typically secrets - the
    | .env file holds your APP_KEY, database credentials and API tokens - and
    | should not be readable by other users on the machine.
    |
    | NOTE: 0600 means only the owner can read the file. If your web server runs
    | as a different user to the one that runs the update, it will not be able
    | to read the .env file and your site will break. In that situation, set
    | this to 0640 and make the file group-readable by the web server instead.
    |
    */

    'protected_file_permissions' => env('STREAMLINE_PROTECTED_FILE_PERMISSIONS', 0600),

    /*
    |--------------------------------------------------------------------------
    | Release Backup Retention
    |--------------------------------------------------------------------------
    |
    | During the initial testing phase, it's useful to keep backups of the old
    | releases. Set to true to keep backups, but you'll need to manually clean
    | the backups periodically.
    |
    */

    'retain_old_releases' => env('STREAMLINE_RETAIN_OLD_RELEASES', true),

    /*
    |--------------------------------------------------------------------------
    | Asset File Extensions
    |--------------------------------------------------------------------------
    |
    | A comma separated list or an array of file extensions that should be
    | filtered out when cleaning up old assets. All other files will be kept.
    | The list has been intentionally kept minimal. If you need to extend it,
    | define the extensions in the .env file.
    |
    | NOTE: the json extension is needed if you have a json manifest file.
    |
    */

    'web_assets_filterable_file_types' => env('STREAMLINE_WEB_ASSET_FILE_TYPES', [
        'json', 'js', 'css', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'avif', 'webp', 'pdf', 'woff', 'woff2', 'ttf', 'eot',
    ]),

    /*
    |--------------------------------------------------------------------------
    | Asset File Revisions
    |--------------------------------------------------------------------------
    |
    | Typically, when building web assets (js, css files), they are given
    | unique filenames that are attached to a build. Upon each new
    | deployment/project update new assets are deployed. This can add up over
    | time. Streamline will read the assets folder and determine the Global most
    | recent versions. It will delete older files.
    |
    | NOTE: Setting this value too low could result in browser caches
    | requesting files that have been deleted from the server
    |
    */

    'web_assets_build_num_revisions' => env('STREAMLINE_WEB_ASSET_HISTORY_NUM', 5),

    /*
    |--------------------------------------------------------------------------
    | Assets Directory
    |--------------------------------------------------------------------------
    |
    | The relative path from the root of the project where the front-end assets
    | are stored. This is used by the updater script to locate the assets
    |
    */

    'web_assets_relative_dir' => env('STREAMLINE_WEB_ASSET_RELATIVE_DIR', 'public/build'),

    /*
    |--------------------------------------------------------------------------
    | Max Asset File Size
    |--------------------------------------------------------------------------
    |
    | Maximum size in bytes for an asset file. Any file larger than this will
    | be ignored. If not needed, set this value to a very high number.
    | Default is 10MB (1024 * 1024 * 5).
    |
    */

    'web_assets_max_file_size' => env('STREAMLINE_WEB_ASSET_MAX_FILE_SIZE', 1024 * 1024 * 5),

    /*
    |--------------------------------------------------------------------------
    | Process Class
    |--------------------------------------------------------------------------
    |
    | This is the class that instantiates the external update process.
    | Typically, it would extend Symfony\Component\Process\Process::class
    | but it doesn't need to.
    |
    | @see Pixelated\Streamline\Actions\InstantiateStreamlineUpdater
    |
    */

    'external_process_class' => Process::class,

    /*
    |--------------------------------------------------------------------------
    | Runner Class
    |--------------------------------------------------------------------------
    |
    | This is the class that is called via an external process (see above)
    | It loads all the necessary dependencies and settings to run the
    | updater class.
    |
    | It can be all-inclusive and do the heavy-lifting of the update process.
    | or it can be more focused and execute an external class such as what
    | this project does.
    |
    | @see Pixelated\Streamline\Actions\InstantiateStreamlineUpdater
    |
    */

    'runner_class' => StreamlineUpdater::class,

    /*
    |--------------------------------------------------------------------------
    | Update Pipeline
    |--------------------------------------------------------------------------
    |
    | These classes (that extend Pixelated\Streamline\Pipeline\Pipe) are used
    | to define the pipeline for the updater script. Order is relevant.
    |
    */

    'pipeline-update' => [
        WarnUnverifiedRelease::class,
        CheckLaravelBasePathWritable::class,
        CheckComposer::class,
        GetNextAvailableReleaseVersion::class,
        VerifyVersion::class,
        MakeTempDir::class,
        DownloadRelease::class,
        VerifyReleaseSignature::class,
        VerifyReleaseChecksum::class,
        UnpackRelease::class,
        BackupCurrentInstallation::class,
        RunUpdate::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline Cleanup
    |--------------------------------------------------------------------------
    |
    | Anything in this class will be executed after all pipeline steps have
    | completed, even if an error occurs during the pipeline execution.
    |
    */

    'pipeline-finish-class' => FinishUpdate::class,
];
