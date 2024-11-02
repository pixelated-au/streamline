<?php

namespace Pixelated\Streamline;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Str;
use Pixelated\Streamline\Commands\ListCommand;
use Pixelated\Streamline\Commands\UpdateCommand;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class StreamlineServiceProvider extends PackageServiceProvider // implements DeferrableProvider
{
    public function configurePackage(Package $package): void
    {
        /* @see https://github.com/spatie/laravel-package-tools */
        $package
            ->name('streamline')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_streamline_table')
            ->runsMigrations()
            ->hasCommands(UpdateCommand::class, ListCommand::class)
            ->hasInstallCommand(fn(InstallCommand $command) => $command
                ->publishConfigFile()
                ->publishMigrations()
                ->askToRunMigrations()
                ->copyAndRegisterServiceProviderInApp()
                ->askToStarRepoOnGitHub('pixelated-au/streamline'));
    }

    public function boot(): void
    {
        parent::boot();
        $this->registerAppUpdater();
        AboutCommand::add('Streamline Updater', ['<fg=bright-magenta>Version</>' => $this->getVersionInfo()]);
    }

    protected function getVersionInfo(): string
    {
        return '<fg=bright-magenta>' .
            InstalledVersions::getPrettyVersion("pixelated-au/streamline") .
            '</>';
    }

    protected function registerAppUpdater(): void
    {
        $this->app->singleton(AppUpdater::class, fn(Application $app) => new AppUpdater(
            $app['files'],
            $app->basePath('stubs'),
            static fn() => base64_encode(password_hash(Str::random(20), PASSWORD_BCRYPT))
        ));
    }

//    public function provides(): array
//    {
//        return [AppUpdater::class];
//    }
}
