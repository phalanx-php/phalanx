<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

final class ArgumentHydrator
{
    /**
     * @param array<string, mixed> $json
     * @param class-string<Tool> $toolClass
     * @return array<string, mixed>
     */
    public static function hydrate(array $json, string $toolClass): array
    {
        $constructor = (new \ReflectionClass($toolClass))->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $hydrated = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $json)) {
                $hydrated[$name] = self::coerce($json[$name], $param);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $hydrated[$name] = $param->getDefaultValue();
                continue;
            }

            if ($param->allowsNull()) {
                $hydrated[$name] = null;
                continue;
            }

            throw new \InvalidArgumentException("Missing required argument: {$name}");
        }

        return $hydrated;
    }

    private static function coerce(mixed $value, \ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        if ($typeName === 'int' && is_string($value) && ctype_digit(ltrim($value, '-'))) {
            return (int) $value;
        }

        if ($typeName === 'float' && is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        if ($typeName === 'bool' && is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
        }

        if (enum_exists($typeName) && is_string($value)) {
            /** @var class-string<\BackedEnum> $typeName */
            return $typeName::from($value);
        }

        return $value;
    }
}
