<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Layout;

final class ConstraintValue
{
    public function __construct(
        private(set) Constraint $kind,
        private(set) int $value,
    ) {}
}
