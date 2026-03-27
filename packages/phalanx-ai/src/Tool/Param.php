<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tool;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Param
{
    public function __construct(
        public string $description,
    ) {}
}
