<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Pipeline\Pipeline;
use Pixelated\Streamline\Pipes\CheckAvailableVersions;
use Pixelated\Streamline\Pipes\Cleanup;
use Pixelated\Streamline\Pipes\DownloadRelease;
use Pixelated\Streamline\Pipes\MakeTempDir;
use Pixelated\Streamline\Pipes\RunUpdate;
use Pixelated\Streamline\Pipes\UnpackRelease;
use Pixelated\Streamline\Pipes\VerifyVersion;
use Pixelated\Streamline\Updater\UpdateBuilder;
use Throwable;

//use Pixelated\Streamline\Actions\CheckAvailableVersions;


class UpdateCommand extends Command
{
    protected $signature = 'streamline:run-update
    {--install-version= : Specify version to install}
    {--force : Force update. Use for overriding the current version.}';

    protected $description = 'CLI update';

    /**
     * @throws \Throwable
     */
    public function handle(): int
    {
        $this->listenForSubProcessEvents();

        $builder = new UpdateBuilder();
        $builder->setRequestedVersion($this->option('install-version'));
//        $builder->setVersionToInstall($this->option('install-version'))
//        ->forceUpdate($this->option('force'));
//
//        $requestedVersion = $this->option('install-version') ??
//            $this->checkAvailableVersions->execute($this->option('force'));

//        $requestedVersion = $this->option('install-version');
//        try {
//            $nextVersion = $this->versionCheck();
//        } catch (RuntimeException $e) {
//            $this->error($e->getMessage());
//            return self::FAILURE;
//        }

//        if (!$this->verifyVersion($requestedVersion, $nextVersion)) {
//            return self::FAILURE;
//        }
        return (new Pipeline($builder))
            ->through([
                CheckAvailableVersions::class,
                VerifyVersion::class,
                MakeTempDir::class,
                DownloadRelease::class,
                UnpackRelease::class,
                RunUpdate::class,
                Cleanup::class,
            ])
            ->catch(function (Throwable $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            })
            ->then(function () {
                return self::SUCCESS;
            });

//        $this->retrieveVersionFromRepository($requestedVersion ?? $nextVersion);
//
//        $this->runUpdate($requestedVersion ?? $nextVersion);
//
//        ArchivedReleaseTools::cleanup();

//        return self::SUCCESS;
    }

    /** @noinspection PhpMethodParametersCountMismatchInspection */
    private function listenForSubProcessEvents(): void
    {
        Event::listen(
            CommandClassCallback::class,
            function (CommandClassCallback $event) {
                match ($event->action) {
                    'comment' => $this->comment($event->value),
                    'info' => $this->info($event->value),
                    'warn' => $this->warn($event->value),
                    'error' => $this->error($event->value),
                    'call' => $this->call($event->value),
                    default => null,
                };
            }
        );
    }

//    /**
//     * @returns \Illuminate\Support\Collection<array-key, string>
//     */
//    protected function versionExists(string $version): bool
//    {
//        return Cache::get(CacheKeysEnum::AVAILABLE_VERSIONS->value)
//            ->contains($version);
//    }

//    private function verifyVersion($requestedVersion, string $nextVersion): bool
//    {
//        if ($requestedVersion) {
//            $this->info("Changing deployment to version: $requestedVersion");
//            if (!$this->versionExists($requestedVersion)) {
//                $this->error("Version $requestedVersion is not a valid version!");
//                return false;
//            }
//
//            if (version_compare($requestedVersion, $nextVersion, '<=')) {
//                $this->error("Version $requestedVersion is not greater than the current version ($nextVersion)");
//                if (!$this->option('force')) {
//                    return false;
//                }
//            }
//        } else if (version_compare($nextVersion, config('streamline.installed_version'), '<=')) {
//            $this->warn("You are currently using the latest version ($nextVersion)");
//        } else {
//            $this->info("Deploying to next available version: $nextVersion");
//        }
//
//        if (!$requestedVersion && !$this->versionExists($nextVersion)) {
//            // This should never happen...
//            $this->error("Unexpected! The next available version: $nextVersion cannot be found.");
//            return false;
//        }
//        return true;
//    }

//    private function runUpdate(string $requestedVersion): void
//    {
//        $this->runUpdate->execute($requestedVersion, function (string $type, string $output) {
//            // @codeCoverageIgnoreStart
//            if ($type === 'err') {
//                $this->error($output);
//                throw new RuntimeException($output);
//            }
//
//            $this->info($output);
//            // @codeCoverageIgnoreEnd
//        });
//    }
}
