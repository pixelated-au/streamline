<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Pixelated\Streamline\Actions\SetInstalledVersionFromConfig;

class InitInstalledVersionCommand extends Command
{
    protected $signature = 'streamline:init-installed-version';

    protected $description = 'Initialise the installed version in cache';

    public function handle(SetInstalledVersionFromConfig $action): int
    {
        $action->execute();

        $this->info('Installed version has been configured.');

        return self::SUCCESS;
    }
}
