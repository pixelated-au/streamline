<?php

namespace Pixelated\Streamline\Pipes;

use Illuminate\Support\Facades\Process;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Pixelated\Streamline\Pipeline\Pipe;
use RuntimeException;

class CheckComposer implements Pipe
{
    public function __invoke(UpdateBuilderInterface $builder): UpdateBuilderInterface
    {
        $composerPath = $this->checkComposerPath(
            $builder->getComposerPath()
        );

        $builder->setComposerPath($composerPath);

        return $builder;
    }

    protected function checkComposerPath(string $composerPath): string
    {
        if (Process::run("$composerPath -V")->failed()) {
            throw new RuntimeException(
                message: 'Error: Cannot find composer. It doesn\'t appear to be installed globally. ' .
                'Please specify the path to composer using the --composer option. ' .
                'See https://getcomposer.org for more information.'
            );
        }

        // return the composer full path by using 'which'. Trim off the newline character which is added by Process::run
        return trim(Process::run('which composer')->output());
    }
}
