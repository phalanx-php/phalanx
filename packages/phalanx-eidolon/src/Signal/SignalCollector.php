<?php

declare(strict_types=1);

namespace Phalanx\Eidolon\Signal;

final class SignalCollector
{
    /** @var list<Signal> */
    private array $signals = [];

    public function add(Signal $signal): void
    {
        $this->signals[] = $signal;
    }

    public function isEmpty(): bool
    {
        return $this->signals === [];
    }

    /**
     * Sorts by priority ascending, serializes each signal, clears the queue.
     *
     * @return list<array<string, mixed>>
     */
    public function drain(): array
    {
        usort($this->signals, static fn(Signal $a, Signal $b) => $a->priority->value <=> $b->priority->value);

        $result        = array_map(static fn(Signal $s) => $s->toArray(), $this->signals);
        $this->signals = [];

        return $result;
    }

    public function flash(string $message, string $level = 'success'): self
    {
        $this->add(new FlashSignal($message, $level));
        return $this;
    }

    public function invalidate(string ...$keys): self
    {
        $this->add(new InvalidateSignal(...$keys));
        return $this;
    }

    public function redirect(string $url, bool $replace = false): self
    {
        $this->add(new RedirectSignal($url, $replace));
        return $this;
    }

    /** @param array<string, mixed> $payload */
    public function event(string $name, array $payload = []): self
    {
        $this->add(new EventSignal($name, $payload));
        return $this;
    }

    public function token(?string $token, ?int $expiresIn = null): self
    {
        $this->add(new TokenSignal($token, $expiresIn));
        return $this;
    }
}
