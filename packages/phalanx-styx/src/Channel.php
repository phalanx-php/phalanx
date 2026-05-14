<?php

declare(strict_types=1);

namespace Phalanx\Styx;

use Closure;
use Generator;
use OpenSwoole\Coroutine\Channel as SwooleChannel;
use Phalanx\Pool\BorrowedValue;
use Throwable;

/**
 * Coroutine-aware channel: bounded, FIFO, suspending on full/empty.
 *
 * Backed by OpenSwoole\Coroutine\Channel. The producer's emit() suspends when
 * the buffer fills; the consumer's consume() suspends when the buffer empties.
 * Both wakeups are coroutine-scheduler driven — no manual deferred plumbing.
 *
 * The withPressure(callable) hook is for external producers that need to be
 * told to pause feeding work in (e.g. a network source you don't want
 * buffering megabytes of data while your consumer is slow). It fires once on
 * the fill→full transition and again on the full→half-drained transition.
 */
final class Channel
{
    public bool $isOpen {
        get => $this->open;
    }

    private static ?\stdClass $sentinel = null;

    private readonly SwooleChannel $chan;

    private bool $open = true;

    private ?Throwable $error = null;

    /** @var ?Closure(bool): void */
    private ?Closure $pressureCallback = null;

    private bool $paused = false;

    public function __construct(
        private readonly int $bufferSize = 32,
    ) {
        $this->chan = new SwooleChannel($bufferSize);
    }

    public function emit(mixed ...$args): void
    {
        if (!$this->open) {
            return;
        }

        $value = count($args) === 1 ? $args[0] : $args;
        self::assertOwnedBoundaryValue($value);

        $this->firePauseIfFilling();
        $this->chan->push($value);
    }

    public function tryEmit(mixed ...$args): bool
    {
        if (!$this->open || $this->chan->length() >= $this->bufferSize) {
            return false;
        }

        $value = count($args) === 1 ? $args[0] : $args;
        self::assertOwnedBoundaryValue($value);

        if (!$this->chan->push($value, 0)) {
            return false;
        }

        $this->firePauseIfFilling();
        return true;
    }

    public function complete(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->chan->close();
    }

    public function error(Throwable $e): void
    {
        if (!$this->open) {
            return;
        }
        $this->error = $e;
        $this->open = false;
        $this->chan->close();
    }

    public function consume(): Generator
    {
        while (true) {
            $value = $this->next();
            if ($value === self::sentinel()) {
                return;
            }

            yield $value;
        }
    }

    public function next(?float $timeout = null): mixed
    {
        $value = $timeout === null ? $this->chan->pop() : $this->chan->pop($timeout);

        if ($value === false) {
            if ($this->chan->errCode === SwooleChannel::CHANNEL_CLOSED && $this->error !== null) {
                throw $this->error;
            }

            return self::sentinel();
        }

        $halfFull = (int) ($this->bufferSize / 2);
        if (
            $this->paused
            && $this->pressureCallback !== null
            && $this->chan->length() <= $halfFull
        ) {
            $this->paused = false;
            ($this->pressureCallback)(false);
        }

        return $value;
    }

    /** @param \Closure(bool): void $fn Must be a static closure; non-static closures in channel pressure callbacks cause reference-cycle leaks. */
    public function withPressure(Closure $fn): self
    {
        $this->pressureCallback = $fn;
        return $this;
    }

    private static function sentinel(): \stdClass
    {
        return self::$sentinel ??= new \stdClass();
    }

    private static function assertOwnedBoundaryValue(mixed $value): void
    {
        if (self::containsBorrowedValue($value)) {
            throw new \LogicException(
                'Borrowed values cannot cross Styx channel boundaries; copy to an owned value before emit().',
            );
        }
    }

    private static function containsBorrowedValue(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof BorrowedValue) {
            return true;
        }

        if (!is_array($value) || $depth > 16) {
            return false;
        }

        foreach ($value as $item) {
            if (self::containsBorrowedValue($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function firePauseIfFilling(): void
    {
        if (
            $this->paused
            || $this->pressureCallback === null
            || $this->chan->length() < $this->bufferSize - 1
        ) {
            return;
        }
        $this->paused = true;
        ($this->pressureCallback)(true);
    }
}
