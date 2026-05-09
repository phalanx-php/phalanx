<?php

declare(strict_types=1);

namespace Phalanx\Boot;

abstract class BootRequirement
{
    protected function __construct(
        private(set) string $kind,
        private(set) string $description,
    ) {
    }

    abstract public function evaluate(AppContext $context): BootEvaluation;
}
