<?php

declare(strict_types=1);

namespace Phalanx\Testing\Probe;

use Closure;
use PHPUnit\Framework\Assert;

final class DisposalProbe
{
    /** @var list<string> */
    public private(set) array $log = [];

    public function track(string $label): Closure
    {
        $log = &$this->log;

        return static function () use ($label, &$log): void {
            $log[] = $label;
        };
    }

    public function assertDisposed(string ...$labels): void
    {
        foreach ($labels as $label) {
            Assert::assertContains($label, $this->log, "Expected '$label' to be disposed");
        }
    }

    public function assertDisposedInOrder(string ...$labels): void
    {
        $actual = array_values($this->log);
        $filtered = array_values(array_intersect($actual, $labels));

        Assert::assertSame(
            array_values($labels),
            $filtered,
            'Disposal order mismatch. Expected: [' . implode(', ', $labels) . '] Got: [' . implode(', ', $filtered) . ']',
        );
    }

    public function reset(): void
    {
        $this->log = [];
    }
}
