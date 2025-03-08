<?php

/** @noinspection PhpMultipleClassDeclarationsInspection */

namespace Pixelated\Streamline\Commands\Traits;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pixelated\Streamline\Events\CommandClassCallback;

/** @codeCoverageIgnore * */
trait OutputSubProcessCalls
{
    public function comment($message, $verbosity = null): void
    {
        !App::runningUnitTests() && Log::debug($message);
        parent::comment($message, $verbosity);
    }

    public function info($message, $verbosity = null): void
    {
        !App::runningUnitTests() && Log::info($message);
        parent::info($message, $verbosity);
    }

    public function warn($message, $verbosity = null): void
    {
        !App::runningUnitTests() && Log::warning($message);
        parent::warn($message, $verbosity);
    }

    public function error($message, $verbosity = null): void
    {
        !App::runningUnitTests() && Log::error($message);
        parent::error($message, $verbosity);
    }

    private function listenForSubProcessEvents(): void
    {
        Event::listen(
            CommandClassCallback::class,
            function(CommandClassCallback $event) {
                $verbosity = $this->output->getVerbosity();

                if ($verbosity < $event->verbosity) {
                    return;
                }
                $this->outputEvent($event);
            }
        );
    }

    public function outputEvent(CommandClassCallback $event): void
    {
        match ($event->action) {
            'comment' => $this->comment($event->value),
            'info'    => $this->info($event->value),
            'warn'    => $this->warn($event->value),
            'error'   => $this->error($event->value),
            'call'    => $this->call($event->value),
            default   => $this->line($event->value),
        };
    }
}
