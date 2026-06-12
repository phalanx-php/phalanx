<?php

declare(strict_types=1);

namespace Phalanx\Supervision;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Identity
{
    public ?string $name;

    public function __construct(
        ?string $name = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Identity name cannot be empty.');
        }

        $this->name = $name;
    }
}
