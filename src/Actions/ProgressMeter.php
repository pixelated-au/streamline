<?php

namespace Pixelated\Streamline\Actions;

use Closure;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use Symfony\Component\Console\Helper\ProgressBar;

class ProgressMeter
{
    private ProgressBar $progressBar;
    public bool $hasStarted = false;

    public readonly Closure $progressCallback;

    public function __construct(private readonly ?OutputStyle $output, private readonly ?float $minSecondsBetweenRedraws = null)
    {
        if (!$output || !app()->runningInConsole()) {
            return;
        }
        $this->initCallback();
    }

    public function __invoke(int $downloadTotal, int $downloadedBytes): void
    {
        if (!isset($this->output)) {
            throw new InvalidArgumentException('OutputStyle needs to be passed in if you want to use the ProgressMeter.');
        }

        $this->progressCallback->call($this, $downloadTotal, $downloadedBytes);
    }

    protected function initCallback(?Closure $callback = null): void
    {
        $this->progressCallback = $callback ?: function (int $downloadTotal, int $downloadedBytes) {
            if (!$this->hasStarted) {
                $this->init((bool)$downloadTotal);
            }

            if (isset($this->progressBar)) {
                $downloadTotal
                    ? $this->progressBar->setProgress((int)round($downloadedBytes / $downloadTotal * 100))
                    : $this->progressBar->advance();
            }
        };
    }

    public function init(bool $hasContentLength): void
    {
        $this->progressBar = $this->output->createProgressBar($hasContentLength ? 100 : 0);
        if (!is_null($this->minSecondsBetweenRedraws)) {
            $this->progressBar->minSecondsBetweenRedraws($this->minSecondsBetweenRedraws);
        }
        $this->progressBar->start();
        $this->hasStarted = true;
    }

    public function finish(): void
    {
        $this->progressBar->finish();
        $this->hasStarted = false;
    }
}
