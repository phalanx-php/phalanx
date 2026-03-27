<?php

declare(strict_types=1);

namespace Phalanx\Ai\Schema;

use Phalanx\Ai\Tool\Param;
use ReflectionClass;
use ReflectionNamedType;

final class StructuredOutputParser
{
    /** @var array<class-string, array<string, mixed>> */
    private static array $schemaCache = [];

    /** @param class-string $class
     *  @return array<string, mixed> */
    public static function generateSchema(string $class): array
    {
        if (isset(self::$schemaCache[$class])) {
            return self::$schemaCache[$class];
        }

        $ref = new ReflectionClass($class);
        $constructor = $ref->getConstructor();

        $properties = [];
        $required = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $schema = self::parameterToJsonSchema($param);

                $attrs = $param->getAttributes(Param::class);
                if ($attrs !== []) {
                    $schema['description'] = $attrs[0]->newInstance()->description;
                }

                $properties[$param->getName()] = $schema;

                if (!$param->isOptional()) {
                    $required[] = $param->getName();
                }
            }
        }

        $structuredAttr = $ref->getAttributes(Structured::class);
        $description = $structuredAttr !== [] ? $structuredAttr[0]->newInstance()->description : '';

        $result = [
            'type' => 'object',
            'properties' => $properties ?: new \stdClass(),
            'required' => $required,
        ];

        if ($description !== '') {
            $result['description'] = $description;
        }

        self::$schemaCache[$class] = $result;

        return $result;
    }

    /** @param class-string $class */
    public static function hydrate(string $class, string $json): object
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $ref = new ReflectionClass($class);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $data)) {
                $type = $param->getType();
                $args[$name] = self::coerce($data[$name], $type);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[$name] = $param->getDefaultValue();
            } else {
                throw new StructuredOutputException(
                    "Missing required field '{$name}' in structured output",
                    $json,
                    ["Field '{$name}' is required"],
                );
            }
        }

        return new $class(...$args);
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    public static function validate(string $class, string $json): array
    {
        $errors = [];

        try {
            self::hydrate($class, $json);
        } catch (StructuredOutputException $e) {
            return $e->validationErrors;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    private static function coerce(mixed $value, ?\ReflectionType $type): mixed
    {
        if ($type === null || !$type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        if (enum_exists($typeName) && is_string($value) && is_subclass_of($typeName, \BackedEnum::class)) {
            /** @var class-string<\BackedEnum> $typeName */
            return $typeName::from($value);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function parameterToJsonSchema(\ReflectionParameter $param): array
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType) {
            return ['type' => 'string'];
        }

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
