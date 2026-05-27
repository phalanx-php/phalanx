<?php

declare(strict_types=1);

namespace Phalanx\DoryBin;

use Phalanx\DoryBin\Verify\VerifyResult;

final class VerifyOutcome
{
    /** @param list<VerifyResult> $results */
    public function __construct(
        private(set) bool $passed,

        private(set) array $results,

        private(set) string $binaryPath,

        private(set) float $totalMs,
    ) {
    }

    /** @return list<VerifyResult> */
    public function failures(): array
    {
        return array_values(array_filter($this->results, static fn(VerifyResult $r): bool => !$r->passed));
    }
}
