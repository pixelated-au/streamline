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
        // The array form is used so the path is passed as a single argument rather than being
        // interpreted by a shell. A value such as `--composer='composer; rm -rf /'` is then treated
        // as a (missing) filename instead of being executed.
        if (Process::run([$composerPath, '-V'])->failed()) {
            throw new RuntimeException(
                message: 'Error: Cannot find composer. It doesn\'t appear to be installed globally. ' .
                'Please specify the path to composer using the --composer option. ' .
                'See https://getcomposer.org for more information.'
            );
        }

        // An explicit path is kept as given. Resolving it through `which` would discard the path the
        // caller supplied via --composer, which is precisely the case where composer is *not* on the
        // PATH, and would hand back either the wrong binary or an empty string.
        if (str_contains($composerPath, DIRECTORY_SEPARATOR)) {
            return realpath($composerPath) ?: $composerPath;
        }

        // A bare command name is resolved to an absolute path because the updater runs the command
        // from a different working directory, and may not inherit the same PATH.
        $resolvedPath = trim(Process::run(['which', $composerPath])->output());

        return $resolvedPath ?: $composerPath;
    }
}
