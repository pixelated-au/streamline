<?php

namespace Pixelated\Streamline\Tests\Feature\Traits;

use Illuminate\Support\Facades\Process;

trait CheckComposerPath
{
    public function mockComposerPath(string $composerPath): self
    {
        // Commands are dispatched as arrays so that arguments are not shell-interpreted. Symfony
        // escapes each argument when building the command line, so the fakes are matched with
        // wildcards rather than against an exact string.
        Process::fake([
            "*$composerPath*-V*" => Process::result('Composer version 2.0.0'),
            '*which*'            => Process::result($composerPath),
        ]);

        return $this;
    }
}
