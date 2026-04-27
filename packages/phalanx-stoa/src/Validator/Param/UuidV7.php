<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Validator\Param;

use Phalanx\Stoa\Contract\RouteParamValidator;

/**
 * Route parameter validator that constrains a parameter to UUID v7 format.
 *
 * UUID v7 uses version nibble 7 (instead of 4 for v4). The pattern is
 * sufficient -- validate() is a no-op.
 */
final class UuidV7 implements RouteParamValidator
{
    public function validate(string $name, string $value): ?string
    {
        return null;
    }

    public function toPattern(): string
    {
        return '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
    }
}
