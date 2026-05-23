<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Validator\Param;

use Phalanx\Stoa\Contract\RouteParamValidator;

/**
 * Route parameter validator that constrains a numeric parameter to a range.
 *
 * The regex ensures only digits reach validate(), so validate() only needs
 * to cast and check bounds.
 */
final class IntInRange implements RouteParamValidator
{
    public function __construct(
        private readonly int $min = PHP_INT_MIN,
        private readonly int $max = PHP_INT_MAX,
    ) {
    }

    public function validate(string $name, string $value): ?string
    {
        $int = (int) $value;

        if ($int < $this->min) {
            return "Parameter {$name} must be at least {$this->min}";
        }

        if ($int > $this->max) {
            return "Parameter {$name} must be at most {$this->max}";
        }

        return null;
    }

    public function toPattern(): string
    {
        return '\d+';
    }
}
