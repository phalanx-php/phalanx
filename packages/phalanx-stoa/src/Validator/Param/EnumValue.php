<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Validator\Param;

use Phalanx\Stoa\Contract\RouteParamValidator;
use UnitEnum;

/**
 * Route parameter validator that constrains a parameter to a backed enum value.
 *
 * No regex pattern is provided -- enum cases are too dynamic to precompute.
 * Validation happens imperatively by calling tryFrom() on the enum class.
 *
 * @template T of UnitEnum
 */
final class EnumValue implements RouteParamValidator
{
    /**
     * @param class-string<T> $enum
     */
    public function __construct(private readonly string $enum)
    {
    }

    public function validate(string $name, string $value): ?string
    {
        $enumClass = $this->enum;

        if (!method_exists($enumClass, 'tryFrom')) {
            // Pure (non-backed) enum: check by case name
            foreach ($enumClass::cases() as $case) {
                if ($case->name === $value) {
                    return null;
                }
            }
        } elseif ($enumClass::tryFrom($value) !== null) {
            return null;
        }

        $cases = array_map(
            static fn(UnitEnum $c): string => $c instanceof \BackedEnum ? (string) $c->value : $c->name,
            $enumClass::cases(),
        );

        return "Parameter {$name} must be one of: " . implode(', ', $cases);
    }

    public function toPattern(): ?string
    {
        return null;
    }
}
