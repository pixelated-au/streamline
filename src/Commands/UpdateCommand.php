<?php

namespace Pixelated\Streamline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Pixelated\Streamline\Events\CommandClassCallback;
use Pixelated\Streamline\Pipeline\Pipeline;
use Pixelated\Streamline\Updater\UpdateBuilder;
use Throwable;

class UpdateCommand extends Command
{
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
                $this->error($e->getMessage());
                return self::FAILURE;
            })
            ->finally(config('streamline.cleanup'))
            ->then(function () {
                return self::SUCCESS;
            });
    }

    /**
     * @noinspection PhpVoidFunctionResultUsedInspection
     */
    private function listenForSubProcessEvents(): void
    {
        // @codeCoverageIgnoreStart
        Event::listen(
            CommandClassCallback::class,
            fn(CommandClassCallback $event) => match ($event->action) {
                'comment' => $this->comment($event->value),
                'info' => $this->info($event->value),
                'warn' => $this->warn($event->value),
                'error' => $this->error($event->value),
                'call' => $this->call($event->value),
                default => null,
            }
        );
        // @codeCoverageIgnoreEnd
    }
}
