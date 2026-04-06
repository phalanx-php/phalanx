<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use Closure;
use Phalanx\Exception\CancelledException;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;

final class CancellationToken
{
    private bool $cancelled = false;

    /** @var list<Closure> */
    private array $callbacks = [];

    private ?TimerInterface $timer = null;

    public bool $isCancelled {
        get => $this->cancelled;
    }

    private function __construct()
    {
    }

    /** "none" = no cancellation source, not immutable. cancel() still works. */
    public static function none(): self
    {
        return new self();
    }

    public static function create(): self
    {
        return new self();
    }

    public static function timeout(float $seconds): self
    {
        $token = new self();

        $token->timer = Loop::addTimer($seconds, static function () use ($token): void {
            $token->cancel();
        });

        return $token;
    }

    public static function composite(self ...$tokens): self
    {
        $composite = new self();

        foreach ($tokens as $token) {
            if ($token->cancelled) {
                $composite->cancel();
                return $composite;
            }

            /**
             * Callback holds composite alive in source token's list until source fires.
             *
             * @see https://www.php.net/manual/en/class.weakreference.php
             */
            $token->onCancel(static function () use ($composite): void {
                $composite->cancel();
            });
        }

        return $composite;
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;

        if ($this->timer !== null) {
            Loop::cancelTimer($this->timer);
            $this->timer = null;
        }

        foreach ($this->callbacks as $callback) {
            $callback();
        }

        $this->callbacks = [];
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new CancelledException();
        }
    }

    public function onCancel(Closure $callback): void
    {
        if ($this->cancelled) {
            $callback();
            return;
        }

        $this->callbacks[] = $callback;
    }
}
