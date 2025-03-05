<?php

/** @noinspection PhpUnused */

namespace Pixelated\Streamline\Testing\Mocks;

use Pixelated\Streamline\Actions\ProgressMeter;

class ProgressMeterFake extends ProgressMeter
{
    /** @noinspection MagicMethodsValidityInspection
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct()
    {
        $this->initCallback(function() {});
    }

    public function __invoke(int $downloadTotal, int $downloadedBytes): void
    {
        // Do nothing
    }

    public function init(bool $hasContentLength): void
    {
        // Do nothing
    }

    public function hasStarted(): bool
    {
        return true;
    }

    public function finish(): void
    {
        // Do nothing
    }
}
