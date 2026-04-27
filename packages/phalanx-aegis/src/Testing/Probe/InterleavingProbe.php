<?php

declare(strict_types=1);

namespace Phalanx\Testing\Probe;

use PHPUnit\Framework\Assert;

final class InterleavingProbe
{
    /** @var list<string> */
    public private(set) array $events = [];

    public function checkpoint(string $label): void
    {
        $this->events[] = $label;
    }

    public function assertInterleaved(string $prefixA, string $prefixB): void
    {
        Assert::assertNotEmpty($this->events, 'No events recorded');

        $aIndices = [];
        $bIndices = [];

        foreach ($this->events as $i => $event) {
            if (str_starts_with($event, $prefixA)) {
                $aIndices[] = $i;
            } elseif (str_starts_with($event, $prefixB)) {
                $bIndices[] = $i;
            }
        }

        Assert::assertNotEmpty($aIndices, "No events with prefix '$prefixA' found");
        Assert::assertNotEmpty($bIndices, "No events with prefix '$prefixB' found");

        $aFirst = $aIndices[0];
        $aLast = $aIndices[count($aIndices) - 1];
        $bFirst = $bIndices[0];
        $bLast = $bIndices[count($bIndices) - 1];

        $bBetweenA = array_any($bIndices, static fn(int $i): bool => $i > $aFirst && $i < $aLast);
        $aBetweenB = array_any($aIndices, static fn(int $i): bool => $i > $bFirst && $i < $bLast);

        Assert::assertTrue(
            $bBetweenA || $aBetweenB,
            'Fibers did not interleave. Events: [' . implode(', ', $this->events) . ']',
        );
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
