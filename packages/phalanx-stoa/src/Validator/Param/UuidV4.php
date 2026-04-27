<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Validator\Param;

use Phalanx\Stoa\Contract\RouteParamValidator;

/**
 * Route parameter validator that constrains a parameter to UUID v4 format.
 *
 * The pattern is sufficient -- UUID v4 is fully expressible as a regex.
 * validate() is a no-op; the pattern does all the work at FastRoute level.
 */
final class UuidV4 implements RouteParamValidator
{
    public function validate(string $name, string $value): ?string
    {
        return null;
    }

    public function toPattern(): string
    {
        return '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
    }
}
