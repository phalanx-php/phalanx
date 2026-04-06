<?php

declare(strict_types=1);

namespace Phalanx\Http\OpenApi;

use Phalanx\Http\Response\Accepted;
use Phalanx\Http\Response\Created;
use Phalanx\Http\Response\NoContent;
use Phalanx\Http\ToResponse;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

class SchemaReflector
{
    /** @var array<string, array<string, mixed>> */
    private static array $schemaCache = [];

    /**
     * Reflect on a class constructor to produce an OpenAPI schema.
     *
     * @param class-string $className
     * @return array<string, mixed>
     */
    public static function classSchema(string $className): array
    {
        if (isset(self::$schemaCache[$className])) {
            return self::$schemaCache[$className];
        }

        $ref = new ReflectionClass($className);
        $constructor = $ref->getConstructor();

        $properties = [];
        $required = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                $properties[$name] = self::parameterToSchema($param);

                if (!$param->isOptional() && !$param->getType()?->allowsNull()) {
                    $required[] = $name;
                }
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties ?: new \stdClass(),
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        self::$schemaCache[$className] = $schema;

        return $schema;
    }

    /**
     * Map a PHP return type to an OpenAPI response schema.
     *
     * @return array<string, mixed>|null
     */
    public static function returnTypeSchema(?ReflectionType $type): ?array
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            return self::namedTypeToSchema($type);
        }

        return null;
    }

    /**
     * Unwrap response wrapper types to [statusCode, innerSchema].
     *
     * @return array{int, array<string, mixed>|null}
     */
    public static function unwrapResponseWrapper(ReflectionNamedType $type): array
    {
        $name = $type->getName();

        if ($name === 'void') {
            return [204, null];
        }

        if ($name === NoContent::class) {
            return [204, null];
        }

        if ($name === Created::class) {
            return [201, null];
        }

        if ($name === Accepted::class) {
            return [202, null];
        }

        if (is_subclass_of($name, ToResponse::class)) {
            return [200, null];
        }

        if (class_exists($name)) {
            return [200, self::classSchema($name)];
        }

        $schema = self::namedTypeToSchema($type);

        return [200, $schema];
    }

    /** @return array<string, mixed> */
    protected static function parameterToSchema(ReflectionParameter $param): array
    {
        $type = $param->getType();
        $schema = [];

        if ($type instanceof ReflectionNamedType) {
            $schema = self::namedTypeToSchema($type);
        } elseif ($type instanceof ReflectionUnionType) {
            $schemas = [];
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType && $t->getName() !== 'null') {
                    $schemas[] = self::namedTypeToSchema($t);
                }
            }
            if (count($schemas) === 1) {
                $schema = $schemas[0];
            } elseif (count($schemas) > 1) {
                $schema = ['anyOf' => $schemas];
            }
        }

        if ($type?->allowsNull() && isset($schema['type'])) {
            $schema['nullable'] = true;
        }

        if ($param->isDefaultValueAvailable()) {
            $default = $param->getDefaultValue();
            if ($default instanceof \UnitEnum) {
                $schema['default'] = $default->value ?? $default->name;
            } else {
                $schema['default'] = $default;
            }
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    protected static function namedTypeToSchema(ReflectionNamedType $type): array
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
            'mixed' => [],
            \DateTimeInterface::class,
            \DateTimeImmutable::class,
            \DateTime::class => ['type' => 'string', 'format' => 'date-time'],
            default => class_exists($name) ? self::classSchema($name) : ['type' => 'object'],
        };
    }
}
