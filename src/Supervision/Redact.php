<?php

declare(strict_types=1);

namespace Phalanx\Supervision;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Redact
{
    public ?string $label;

    public function __construct(
        ?string $label = null,
    ) {
        if ($label === '') {
            throw new InvalidArgumentException('Redaction label cannot be empty.');
        }

        $this->label = $label;
    }
}
