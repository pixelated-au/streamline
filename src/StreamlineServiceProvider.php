<?php

namespace Pixelated\Streamline;

use Composer\InstalledVersions;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Config;
use Pixelated\Streamline\Actions\CreateArchive;
use Pixelated\Streamline\Commands\CheckCommand;
use Pixelated\Streamline\Commands\CleanAssetsDirectoryCommand;
use Pixelated\Streamline\Commands\FinishUpdateCommand;
use Pixelated\Streamline\Commands\InitInstalledVersionCommand;
use Pixelated\Streamline\Commands\ListCommand;
use Pixelated\Streamline\Commands\UpdateCommand;
use Pixelated\Streamline\Macros\ConfigCommaToArrayMacro;
use Pixelated\Streamline\Testing\Mocks\UpdateRunnerFake;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class StreamlineServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /* @see https://github.com/spatie/laravel-package-tools */
        $package
            ->name('streamline')
            ->hasConfigFile()
            ->hasCommands(
                UpdateCommand::class,
                CheckCommand::class,
                CleanAssetsDirectoryCommand::class,
                FinishUpdateCommand::class,
                InitInstalledVersionCommand::class,
                ListCommand::class,
            )
            ->hasInstallCommand(fn (InstallCommand $command) => $command->publishConfigFile());
    }

    public function registeringPackage(): void
    {
        $this->app->bind(
            CreateArchive::class,
            fn (Application $app) => new CreateArchive(
                sourceFolder: base_path(),
                destinationPath: config('streamline.backup_dir'),
                filename: 'backup-' . date('Ymd_His') . '.tgz',
            )
        );

        if ($this->app->environment('local')) {
            // @codeCoverageIgnoreStart
            Config::set('streamline.runner_class', UpdateRunnerFake::class);
            // @codeCoverageIgnoreEnd
        }

        $this->app->resolving(OutputStyle::class, fn (OutputStyle $outputStyle) => $this->app
            // Laravel resolves OutputStyle with make(). This means it won't be re-resolved which
            // means it can't be reused later. This is why we bind() it to the app instance
            ->bindIf(OutputStyle::class, fn () => $outputStyle)
        );
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function bootingPackage(): void
    {
        ConfigCommaToArrayMacro::register();

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->app->make('config');
        $config->set('logging.channels.streamline', $config->get('streamline.logging'));

        AboutCommand::add('Streamline Updater', ['<fg=bright-magenta>Version</>' => $this->getVersionInfo()]);
    }

    protected function getVersionInfo(): string
    {
        return '<fg=bright-magenta>' .
            InstalledVersions::getPrettyVersion('pixelated-au/streamline') .
            '</>';
    }
}
