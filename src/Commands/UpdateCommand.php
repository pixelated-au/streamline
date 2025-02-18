<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Pixelated\Streamline\Commands\Traits\GitHubApi;
use Pixelated\Streamline\Commands\Traits\OutputSubProcessCalls;
use Pixelated\Streamline\Pipeline\Pipeline;
use Pixelated\Streamline\Updater\UpdateBuilder;
use Throwable;

class UpdateCommand extends Command
{
    use GitHubApi;
    use OutputSubProcessCalls;

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

        $builder = (new UpdateBuilder)
            ->setRequestedVersion($this->option('install-version'))
            ->forceUpdate($this->option('force'));

        return (new Pipeline($builder))
            ->through(config('streamline.update-pipeline'))
            ->catch(function (Throwable $e) {
                $this->error($e->getFile() . ', #' . $e->getLine() . ': ' . $e->getMessage());

                return self::FAILURE;
            })
            ->finally(config('streamline.cleanup'))
            ->then(function () {
                return self::SUCCESS;
            });
    }
}
