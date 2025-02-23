<?php

namespace Pixelated\Streamline\Testing\Mocks;

use Illuminate\Support\Facades\Log;

class UpdateRunnerFake
{
    public function run(): void
    {
        Log::debug('Update runner stub executed in lieu of real update process. Refer to StreamlineServiceProvider::registeringPackage()');
    }
}
