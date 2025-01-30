<?php

namespace Pixelated\Streamline\Pipeline;

use Closure;
use Pixelated\Streamline\Interfaces\UpdateBuilderInterface;
use Throwable;

class Pipeline
{
    /** @var \Pixelated\Streamline\Pipeline\Pipe[] */
    protected array $pipes = [];
    protected ?Closure $exceptionHandler = null;
    private Closure $destination;
    private ?Closure $finallyHandler = null;

    public function __construct(protected UpdateBuilderInterface $builder)
    {
    }

    /**
     * @param \Pixelated\Streamline\Pipeline\Pipe[] $pipes
     */
    public function through(array $pipes): static
    {
        $this->pipes = $pipes;
        return $this;
    }

    /**
     * @param \Closure $exceptionHandler
     * @return $this
     */
    public function catch(Closure $exceptionHandler): static
    {
        $this->exceptionHandler = $exceptionHandler;
        return $this;
    }

    /**
     * @param Closure $finallyHandler
     * @return $this
     */
    public function finally(Closure $finallyHandler): static
    {
        $this->finallyHandler = $finallyHandler;
        return $this;
    }

    /**
     * @param Closure(): mixed $destination
     */
    public function then(Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $this->prepareDestination($destination)
        );

        try {
            // This will ultimately call the $destination closure with the result of the pipeline
            $result = $pipeline($this->builder);
        } finally {
            if ($this->finallyHandler) {
                ($this->finallyHandler)($this->builder);
            }
        }

        return $result;
    }

    protected function carry(): Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                try {
                    if (!is_callable($pipe)) {
                        $pipe = app()->make($pipe);
                    }
                    $result = $pipe($passable);

                    if (!$result instanceof UpdateBuilderInterface) {
                        return ($this->destination)($result);
                    }

                    return $stack($result);
                } catch (Throwable $e) {
                    if ($this->exceptionHandler) {
                        return ($this->exceptionHandler)($e, $passable);
                    }
                    throw $e;
                }
            };
        };
    }

    protected function prepareDestination(Closure $destination): Closure
    {
        $this->destination = function ($passable) use ($destination) {
            try {
                return $destination($passable);
            } catch (Throwable $e) {
                if ($this->exceptionHandler) {
                    return ($this->exceptionHandler)($e, $passable);
                }
                throw $e;
            }
        };
        return $this->destination;
    }
}
