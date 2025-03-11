<?php

/** @noinspection PhpUnusedParameterInspection */

use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipes\BackupCurrentInstallation;
use Pixelated\Streamline\Pipes\CheckLaravelBasePathWritable;
use Pixelated\Streamline\Pipes\DownloadRelease;
use Pixelated\Streamline\Pipes\GetNextAvailableReleaseVersion;
use Pixelated\Streamline\Pipes\MakeTempDir;
use Pixelated\Streamline\Pipes\RunUpdate;
use Pixelated\Streamline\Pipes\UnpackRelease;
use Pixelated\Streamline\Pipes\VerifyVersion;
use Symfony\Component\Process\PhpExecutableFinder;
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

    'installed_version' => env('STREAMLINE_APPLICATION_VERSION_INSTALLED'),

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
    */

    'protected_files' => env('STREAMLINE_PROTECTED_PATHS', ['.env', 'storage/logs/streamline.log']),

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
    | Temporary Work Directory
    |--------------------------------------------------------------------------
    |
    | The temporary directory used to extract and process the release archive
    | It will be located from your root installation directory. Eg:
    | app_root/.streamline_tmp
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
    | be set to this value.
    |
    */

    'directory_permissions' => (int) env('STREAMLINE_DIRECTORY_PERMISSIONS', 0755),

    /*
    |--------------------------------------------------------------------------
    | Default File Permissions
    |--------------------------------------------------------------------------
    |
    | After running the updater script, the permissions of the files will be
    | set to this value.
    |
    */

    'file_permissions' => (int) env('STREAMLINE_FILE_PERMISSIONS', 0644),

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
        CheckLaravelBasePathWritable::class,
        GetNextAvailableReleaseVersion::class,
        VerifyVersion::class,
        MakeTempDir::class,
        DownloadRelease::class,
        UnpackRelease::class,
        BackupCurrentInstallation::class,
        RunUpdate::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline Cleanup
    |--------------------------------------------------------------------------
    |
    | Anything in this function will be executed after all pipeline steps have
    | completed. Even if an error occurs during the pipeline execution.
    |
    */

    'pipeline-finish' => static function(UpdateBuilderInterface $builder) {
        $process = resolve(\Symfony\Component\Process\Process::class, [
            'command' => [(new PhpExecutableFinder)->find(), 'artisan', 'streamline:finish-update'],
            'cwd'     => $builder->getBasePath(),
        ]);
        $process->run();

        if ($process->isSuccessful()) {
            CommandClassCallback::dispatch('info', $process->getOutput());
        } else {
            CommandClassCallback::dispatch('error', $process->getErrorOutput());
        }
    },

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configuration of logging. Primarily used for debugging the deletion of
    | old assets during the cleanup process.
    |
    */

    'logging' => [
        'driver' => 'single',
        'path'   => storage_path('logs/streamline.log'),
        'level'  => env('LOG_LEVEL', 'debug'),
    ],
];
