<?php

namespace Pixelated\Streamline;

use Composer\InstalledVersions;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Foundation\Console\AboutCommand;
use Pixelated\Streamline\Commands\CheckCommand;
use Pixelated\Streamline\Commands\CleanAssetsDirectoryCommand;
use Pixelated\Streamline\Commands\ListCommand;
use Pixelated\Streamline\Commands\UpdateCommand;
use Pixelated\Streamline\Macros\ConfigCommaToArrayMacro;
use Pixelated\Streamline\Services\CleanUpAssets;
use Pixelated\Streamline\Services\GitHubApi;
use Pixelated\Streamline\Services\ZipArchive;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class StreamlineServiceProvider extends PackageServiceProvider implements DeferrableProvider
{
    public function configurePackage(Package $package): void
    {
        /* @see https://github.com/spatie/laravel-package-tools */
        $package
            ->name('streamline')
            ->hasConfigFile()
            ->hasCommands(
                UpdateCommand::class,
                ListCommand::class,
                CheckCommand::class,
                CleanAssetsDirectoryCommand::class
            )
            ->hasInstallCommand(fn(InstallCommand $command) => $command->publishConfigFile());
    }

    public function registeringPackage(): void
    {
        $this->app->resolving(OutputStyle::class, fn(OutputStyle $outputStyle) => $this->app
            // Laravel resolves OutputStyle with make(). This means it won't be re-resolved which
            // means it can't be reused later. This is why we bind() it to the app instance
            ->bindIf(OutputStyle::class, fn() => $outputStyle)
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

    public function provides(): array
    {
        // @codeCoverageIgnoreStart
        return [
            GitHubApi::class,
            CleanUpAssets::class,
            ZipArchive::class,
        ];
        // @codeCoverageIgnoreEnd
    }
}
