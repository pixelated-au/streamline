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
     * @param Closure(): mixed $destination
     */
    public function then(Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $this->prepareDestination($destination)
        );

        // This will ultimately call the $destination closure with the result of the pipeline
        return $pipeline($this->builder);
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

                    // If $result is not an instance of UpdateBuilderInterface,
                    // it means the pipe didn't return the builder, so we return
                    // the 'destination' early
                    if (!$result instanceof UpdateBuilderInterface) {
                        return ($this->destination)($result);
                    }

                    // Continue to the next item in the pipeline
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
