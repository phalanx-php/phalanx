<?php

declare(strict_types=1);

namespace Phalanx\Boot;

abstract readonly class BootRequirement
{
    protected function __construct(public string $kind, public string $description)
    {
    }

    abstract public function evaluate(AppContext $context): BootEvaluation;
}
