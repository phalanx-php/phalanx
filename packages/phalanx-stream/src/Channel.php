<?php

declare(strict_types=1);

namespace Phalanx\Stream;

use Generator;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use Throwable;

use function React\Async\await;

final class Channel
{
    private bool $open = true;

    /** @var list<mixed> */
    private array $buffer = [];

    private ?Deferred $consumerWaiting = null;

    private ?Deferred $producerWaiting = null;

    private ?Throwable $error = null;

    /** @var ?callable(bool): void */
    private $pressureCallback = null;

    private bool $paused = false;

    public bool $isOpen {
        get => $this->open;
    }

    public function __construct(
        private readonly int $bufferSize = 32,
    ) {
    }

    public function emit(mixed ...$args): void
    {
        if (!$this->open) {
            return;
        }

        $value = count($args) === 1 ? $args[0] : $args;

        $this->buffer[] = $value;

        if ($this->consumerWaiting !== null) {
            $deferred = $this->consumerWaiting;
            $this->consumerWaiting = null;
            Loop::futureTick(static fn() => $deferred->resolve(true));
        }

        if (count($this->buffer) >= $this->bufferSize) {
            if ($this->pressureCallback !== null && !$this->paused) {
                $this->paused = true;
                ($this->pressureCallback)(true);
            }

            if ($this->open && count($this->buffer) >= $this->bufferSize) {
                $this->producerWaiting = new Deferred();
                await($this->producerWaiting->promise());
            }
        }
    }

    public function complete(): void
    {
        if (!$this->open) {
            return;
        }

        $this->open = false;

        if ($this->consumerWaiting !== null) {
            $deferred = $this->consumerWaiting;
            $this->consumerWaiting = null;
            Loop::futureTick(static fn() => $deferred->resolve(false));
        }
    }

    public function error(Throwable $e): void
    {
        if (!$this->open) {
            return;
        }

        $this->error = $e;
        $this->open = false;

        if ($this->consumerWaiting !== null) {
            $deferred = $this->consumerWaiting;
            $this->consumerWaiting = null;
            Loop::futureTick(static fn() => $deferred->resolve(false));
        }
    }

    public function consume(): Generator
    {
        while (true) {
            while ($this->buffer !== []) {
                $value = array_shift($this->buffer);

                if (count($this->buffer) < (int) ($this->bufferSize * 0.5)) {
                    if ($this->paused && $this->pressureCallback !== null) {
                        $this->paused = false;
                        ($this->pressureCallback)(false);
                    }

                    if ($this->producerWaiting !== null) {
                        $deferred = $this->producerWaiting;
                        $this->producerWaiting = null;
                        Loop::futureTick(static fn() => $deferred->resolve(null));
                    }
                }

                yield $value;
            }

            if ($this->error !== null) {
                throw $this->error;
            }

            if (!$this->open) {
                return;
            }

            $this->consumerWaiting = new Deferred();
            $hasData = await($this->consumerWaiting->promise());

            if (!$hasData && $this->buffer === []) {
                if ($this->error !== null) {
                    throw $this->error;
                }

                return;
            }
        }
    }

    public function withPressure(callable $fn): self
    {
        $this->pressureCallback = $fn;

        return $this;
    }
}
