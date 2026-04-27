<?php

declare(strict_types=1);

namespace Phalanx\Testing\Probe;

use PHPUnit\Framework\Assert;
use WeakReference;

final class LeakSensor
{
    /** @var list<array{WeakReference<object>, string}> */
    private array $refs = [];

    public function watch(object $instance, string $label = ''): void
    {
        $this->refs[] = [WeakReference::create($instance), $label ?: $instance::class];
    }

    public function assertAllCollected(): void
    {
        /**
         * Two passes — first may expose inner cycles held by the outer.
         *
         * @see https://www.php.net/manual/en/features.gc.collecting-cycles.php
         * @see https://pages.cs.wisc.edu/~cymen/misc/interests/Bacon01Concurrent.pdf
         */
        gc_collect_cycles();
        gc_collect_cycles();

        foreach ($this->refs as [$ref, $label]) {
            Assert::assertNull(
                $ref->get(),
                "Instance '$label' survived disposal — possible reference cycle",
            );
        }
    }

    public function reset(): void
    {
        $this->refs = [];
    }
}
