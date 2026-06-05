<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Runtime\Memory\RuntimeMemory;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class LeaseExpectation
{
    public function __construct(
        private readonly RuntimeMemory $memory,
    ) {
    }

    public function released(): void
    {
        PHPUnitAssert::assertSame(
            0,
            $this->memory->tables->resourceLeases->count(),
            'Expected every runtime lease to be released.',
        );
    }

    /**
     * Assert that no leases remain for the given `domain` (e.g. "sse-stream",
     * "postgres/main"). Other-domain leases are allowed to persist — useful
     * when one slice of a test asserts a write surface drained while leaving
     * connection-pool leases acquired by a different lane.
     */
    public function releasedFor(string $domain): void
    {
        $remaining = $this->countFor($domain);
        PHPUnitAssert::assertSame(
            0,
            $remaining,
            "Expected every lease for domain '{$domain}' to be released; {$remaining} still held.",
        );
    }

    public function heldFor(string $domain, int $expected): void
    {
        PHPUnitAssert::assertSame(
            $expected,
            $this->countFor($domain),
            "Expected {$expected} lease(s) for domain '{$domain}'.",
        );
    }

    private function countFor(string $domain): int
    {
        $count = 0;
        foreach ($this->memory->tables->resourceLeases as $row) {
            if (is_array($row) && (string) $row['domain'] === $domain) {
                $count++;
            }
        }

        return $count;
    }
}
