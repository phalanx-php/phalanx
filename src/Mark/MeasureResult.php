<?php

declare(strict_types=1);

namespace Phalanx\Mark;

final class MeasureResult
{
    public function __construct(
        private(set) mixed $value,
        private(set) Mark $elapsed,
    ) {
    }
}
