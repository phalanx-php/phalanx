<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Layout;

final class ConstraintValue
{
    public function __construct(
        public private(set) Constraint $kind,
        public private(set) int $value,
    ) {}
}
