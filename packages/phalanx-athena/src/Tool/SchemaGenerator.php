<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

use Phalanx\SelfDescribed;

final class SchemaGenerator
{
    private const array TYPE_MAP = [
        'string'  => 'string',
        'int'     => 'integer',
        'float'   => 'number',
        'bool'    => 'boolean',
        'array'   => 'array',
    ];

    /**
     * @param class-string<Tool> $toolClass
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public static function forTool(string $toolClass): array
    {
        $class = new \ReflectionClass($toolClass);

        return [
            'name'        => $class->getShortName(),
            'description' => self::resolveDescription($class, $toolClass),
            'parameters'  => self::buildParameters($class),
        ];
    }

    /**
     * @param \ReflectionClass<Tool> $class
     * @param class-string<Tool> $toolClass
     */
    private static function resolveDescription(\ReflectionClass $class, string $toolClass): string
    {
        if (is_a($toolClass, SelfDescribed::class, true)) {
            $instance = $class->newInstanceWithoutConstructor();
            /** @var SelfDescribed $instance */
            return $instance->description;
        }

        return $class->getShortName();
    }

    /**
     * @param \ReflectionClass<Tool> $class
     * @return array<string, mixed>
     */
    private static function buildParameters(\ReflectionClass $class): array
    {
        $constructor = $class->getConstructor();

        if ($constructor === null) {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }

        $properties = [];
        $required   = [];

        foreach ($constructor->getParameters() as $param) {
            $attributes = $param->getAttributes(Param::class);

            if ($attributes === []) {
                continue;
            }

            /** @var Param $meta */
            $meta     = $attributes[0]->newInstance();
            $jsonType = self::resolveJsonType($param);
            $property = ['type' => $jsonType, 'description' => $meta->description];

            if (!$meta->required && $meta->default !== null) {
                $property['default'] = $meta->default;
            }

            $properties[$param->getName()] = $property;

            if ($meta->required) {
                $required[] = $param->getName();
            }
        }

        return [
            'type'       => 'object',
            'properties' => $properties,
            'required'   => $required,
        ];
    }

    private static function resolveJsonType(\ReflectionParameter $param): string
    {
        $type = $param->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return 'string';
        }

        return self::TYPE_MAP[$type->getName()] ?? 'string';
    }
}
