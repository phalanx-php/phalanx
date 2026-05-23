<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Audit;

final class RuntimeRisk
{
    public function __construct(
        private(set) string $category,
        private(set) string $symbol,
        private(set) string $file,
        private(set) int $line,
    ) {
    }
}
