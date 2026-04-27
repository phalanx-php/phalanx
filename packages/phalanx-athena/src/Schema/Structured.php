<?php

declare(strict_types=1);

namespace Phalanx\Athena\Schema;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Structured
{
    public function __construct(
        public string $description = '',
    ) {}
}
