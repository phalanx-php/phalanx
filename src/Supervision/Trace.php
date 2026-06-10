<?php

declare(strict_types=1);

namespace Phalanx\Supervision;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Trace
{
    public string $name;

    public function __construct(
        string $name,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Trace name cannot be empty.');
        }

        $this->name = $name;
    }
}
