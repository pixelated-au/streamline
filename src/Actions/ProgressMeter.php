<?php

namespace Pixelated\Streamline\Actions;

use Closure;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use Symfony\Component\Console\Helper\ProgressBar;

class ProgressMeter
{
    private ProgressBar $progressBar;
    protected bool $hasStarted = false;
    protected string $message = '';

    public readonly Closure $progressCallback;

    public function __construct(private readonly ?OutputStyle $output, private readonly ?float $minSecondsBetweenRedraws = null)
    {
        if (!$output || !app()->runningInConsole()) {
            return;
        }
        ProgressBar::setFormatDefinition('custom', ' %current%/%max% [%bar%] %percent:3s%% - %message%');
        $this->initCallback();
    }

    public function __invoke(int $downloadTotal, int $downloadedBytes): void
    {
        if (!isset($this->output)) {
            throw new InvalidArgumentException('OutputStyle needs to be passed in if you want to use the ProgressMeter.');
        }

        $this->progressCallback->call($this, $downloadTotal, $downloadedBytes);
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    protected function initCallback(?Closure $callback = null): void
    {
        $this->progressCallback = $callback ?: function (int $downloadTotal, int $downloadedBytes) {
            if (!$this->hasStarted) {
                $this->init((bool)$downloadTotal);
            }

            if (isset($this->progressBar)) {
                if ($this->message) {
                    $this->progressBar->setMessage($this->message);
                }
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
        if ($this->message) {
            $this->progressBar->setFormat('custom');
            $this->progressBar->setMessage($this->message);
        }
        $this->progressBar->start();
        $this->hasStarted = true;
    }

    public function hasStarted()
    {
        return $this->hasStarted;
    }

    public function finish(): void
    {
        $this->progressBar->finish();
        $this->output->newLine();
        $this->hasStarted = false;
    }
}
