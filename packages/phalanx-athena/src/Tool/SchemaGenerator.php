<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

use Phalanx\SelfDescribed;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

final class SchemaGenerator
{
    /** @var array<class-string, array{name: string, description: string, input_schema: array<string, mixed>}> */
    private static array $cache = [];

    /** @param class-string<Tool> $class
     *  @return array{name: string, description: string, input_schema: array<string, mixed>} */
    public static function generate(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $ref = new ReflectionClass($class);
        $constructor = $ref->getConstructor();

        $toolName = self::classToSnake($ref->getShortName());
        $description = self::extractDescription($ref);

        $properties = [];
        $required = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $properties[$param->getName()] = self::parameterSchema($param);

                if (!$param->isOptional()) {
                    $required[] = $param->getName();
                }
            }
        }

        $schema = [
            'name' => $toolName,
            'description' => $description,
            'input_schema' => [
                'type' => 'object',
                'properties' => $properties ?: new \stdClass(),
                'required' => $required,
            ],
        ];

        self::$cache[$class] = $schema;

        return $schema;
    }

    private static function classToSnake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /** @param ReflectionClass<Tool> $ref */
    private static function extractDescription(ReflectionClass $ref): string
    {
        if ($ref->implementsInterface(SelfDescribed::class)) {
            $instance = $ref->newInstanceWithoutConstructor();
            try {
                return $instance->description;
            } catch (\Throwable) {
            }
        }

        if ($ref->hasProperty('description')) {
            $prop = $ref->getProperty('description');

            $defaults = $ref->getDefaultProperties();
            if (isset($defaults['description'])) {
                return $defaults['description'];
            }

            if ($prop->hasHooks()) {
                $instance ??= $ref->newInstanceWithoutConstructor();
                try {
                    return $instance->description;
                } catch (\Throwable) {
                    return $ref->getShortName();
                }
            }
        }

        return $ref->getShortName();
    }

    /** @return array<string, mixed> */
    private static function parameterSchema(ReflectionParameter $param): array
    {
        $schema = [];
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType) {
            $schema = self::typeSchema($type);
        } elseif ($type instanceof ReflectionUnionType) {
            $schemas = array_map(
                self::typeSchema(...),
                array_filter($type->getTypes(), static fn($t) => $t instanceof ReflectionNamedType),
            );
            if (count($schemas) > 1) {
                $schema = ['anyOf' => array_values($schemas)];
            } elseif (count($schemas) === 1) {
                $schema = reset($schemas);
            }
        }

        $attrs = $param->getAttributes(Param::class);
        if ($attrs !== []) {
            $schema['description'] = $attrs[0]->newInstance()->description;
        }

        if ($param->isDefaultValueAvailable()) {
            $schema['default'] = $param->getDefaultValue();
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function typeSchema(ReflectionNamedType $type): array
    {
        $name = $type->getName();

        if (enum_exists($name)) {
            $ref = new ReflectionClass($name);
            $cases = $ref->getMethod('cases')->invoke(null);
            $values = array_map(static fn($c) => $c->value ?? $c->name, $cases);

            return ['type' => 'string', 'enum' => $values];
        }

        return match ($name) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            default => ['type' => 'object'],
        };
    }
}
