<?php

declare(strict_types=1);

namespace Phalanx\Http\Validator\Param;

use Phalanx\Http\Contract\RouteParamValidator;

/**
 * Route parameter validator that constrains a parameter to a fixed set of
 * allowed string values.
 *
 * No regex pattern is provided -- the allowed set is validated imperatively
 * so error messages can list the valid options.
 */
final class OneOf implements RouteParamValidator
{
    /** @var list<string> */
    private readonly array $allowed;

    public function __construct(string ...$allowed)
    {
        $this->allowed = array_values($allowed);
    }

    public function validate(string $name, string $value): ?string
    {
        if (in_array($value, $this->allowed, strict: true)) {
            return null;
        }

        return "Parameter {$name} must be one of: " . implode(', ', $this->allowed);
    }

    public function toPattern(): ?string
    {
        return null;
    }
}
