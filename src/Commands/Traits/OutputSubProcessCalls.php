<?php

namespace Pixelated\Streamline\Commands\Traits;

use Illuminate\Support\Facades\Event;
use Pixelated\Streamline\Events\CommandClassCallback;

trait OutputSubProcessCalls {
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
