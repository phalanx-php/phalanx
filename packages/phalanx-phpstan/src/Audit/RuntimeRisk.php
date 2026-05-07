<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Audit;

final class RuntimeRisk
{
    public function __construct(
        public private(set) string $category,
        public private(set) string $symbol,
        public private(set) string $file,
        public private(set) int $line,
    ) {
    }
}
