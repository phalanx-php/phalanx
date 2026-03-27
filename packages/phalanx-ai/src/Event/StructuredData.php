<?php

declare(strict_types=1);

namespace Phalanx\Ai\Event;

final readonly class StructuredData
{
    public function __construct(
        public ?string $field,
        public mixed $value,
    ) {}
}
