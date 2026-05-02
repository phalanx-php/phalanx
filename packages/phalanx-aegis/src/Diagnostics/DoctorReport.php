<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

/**
 * @implements \IteratorAggregate<int, DoctorCheck>
 */
final readonly class DoctorReport implements \IteratorAggregate
{
    /** @param list<DoctorCheck> $checks */
    public function __construct(public array $checks)
    {
    }

    public function isHealthy(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check->ok) {
                return false;
            }
        }

        return true;
    }

    public function getIterator(): \Traversable
    {
        yield from $this->checks;
    }

    /** @return list<array{name: string, ok: bool, detail: string}> */
    public function toArray(): array
    {
        return array_map(static fn(DoctorCheck $check): array => $check->toArray(), $this->checks);
    }
}
