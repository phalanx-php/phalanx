<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Support;

final class ClockProbe
{
    /** @var list<float> */
    private array $times;

    public function __construct(float ...$times)
    {
        $this->times = [];
        foreach ($times as $time) {
            $this->times[] = $time;
        }
    }

    public function __invoke(): float
    {
        if ($this->times === []) {
            throw new \RuntimeException('Clock probe exhausted.');
        }

        return array_shift($this->times);
    }

    public function isExhausted(): bool
    {
        return $this->times === [];
    }
}
