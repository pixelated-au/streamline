<?php

namespace Pixelated\Streamline\Tests\Feature\Traits;

use Illuminate\Support\Facades\Process;

trait CheckComposerPath
{
    public function mockComposerPath(string $composerPath): self
    {
        Process::fake([
            "$composerPath -V" => Process::result('Composer version 2.0.0'),
            'which composer'   => Process::result($composerPath),
        ]);

        return $this;
    }
}
