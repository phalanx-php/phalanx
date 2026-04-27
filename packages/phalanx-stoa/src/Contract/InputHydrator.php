<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Contract;

use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\ValidationException;
use Phalanx\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class InputHydrator
{
    /** @var array<class-string, ?InputMeta> */
    private static array $metaCache = [];

    /** @var array<class-string, int> */
    private static array $paramCountCache = [];

    /** @var list<class-string> */
    private static array $scopeTypes = [
        Scope::class,
    ];

    /**
     * Count the parameters declared on the handler's __invoke method.
     *
     * Used to short-circuit hydration for handlers that take no parameters
     * at all (not even a scope), returning an empty args array.
     *
     * @param class-string<Scopeable|Executable>|Scopeable|Executable $handler
     */
    public static function paramCount(string|Scopeable|Executable $handler): int
    {
        $key = is_string($handler) ? $handler : $handler::class;

        if (array_key_exists($key, self::$paramCountCache)) {
            return self::$paramCountCache[$key];
        }

        $count = (new ReflectionClass($key))->getMethod('__invoke')->getNumberOfParameters();
        self::$paramCountCache[$key] = $count;

        return $count;
    }

    /**
     * Reflect on the handler's __invoke and find the typed input parameter.
     *
     * @param class-string|Scopeable|Executable $handler
     */
    public static function meta(string|Scopeable|Executable $handler): ?InputMeta
    {
        $key = is_string($handler) ? $handler : $handler::class;

        if (array_key_exists($key, self::$metaCache)) {
            return self::$metaCache[$key];
        }

        $ref = (new ReflectionClass($key))->getMethod('__invoke');
        $meta = null;

        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $typeName = $type->getName();

            if (self::isScopeType($typeName)) {
                continue;
            }

            if (class_exists($typeName)) {
                $meta = new InputMeta(
                    inputClass: $typeName,
                    paramName: $param->getName(),
                );
                break;
            }
        }

        self::$metaCache[$key] = $meta;

        return $meta;
    }

    /**
     * Resolve handler arguments: scope + any hydrated inputs.
     *
     * Handlers with zero parameters receive an empty args array. Handlers
     * with only a scope parameter receive [$scope]. Handlers with a scope
     * plus a typed DTO parameter receive [$scope, $dto].
     *
     * @param class-string<Scopeable|Executable>|Scopeable|Executable $handler
     * @return list<mixed>
     */
    public static function resolve(
        string|Scopeable|Executable $handler,
        RequestScope $scope,
    ): array {
        if (self::paramCount($handler) === 0) {
            return [];
        }

        $meta = self::meta($handler);

        if ($meta === null) {
            return [$scope];
        }

        $source = InputSource::fromMethod($scope->method());
        $data = match ($source) {
            InputSource::Body => $scope->body->all(),
            InputSource::Query => $scope->query->all(),
        };

        $dto = self::hydrate($meta->inputClass, $data);

        return [$scope, $dto];
    }

    /**
     * Hydrate a DTO from raw data using constructor reflection.
     *
     * @param class-string $class
     * @param array<string, mixed> $data
     */
    protected static function hydrate(string $class, array $data): object
    {
        $ref = new ReflectionClass($class);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $args = [];
        $errors = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $exists = array_key_exists($name, $data);

            if (!$exists && !$param->isOptional() && !self::isNullableParam($param)) {
                $errors[$name][] = 'This field is required';
                continue;
            }

            if (!$exists) {
                if ($param->isDefaultValueAvailable()) {
                    $args[$name] = $param->getDefaultValue();
                } elseif (self::isNullableParam($param)) {
                    $args[$name] = null;
                }
                continue;
            }

            $value = $data[$name];

            if ($value === null && self::isNullableParam($param)) {
                $args[$name] = null;
                continue;
            }

            $type = $param->getType();
            if (!$type instanceof ReflectionNamedType) {
                $args[$name] = $value;
                continue;
            }

            $coerced = self::coerce($value, $type, $name, $errors);
            if (!array_key_exists($name, $errors)) {
                $args[$name] = $coerced;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $dto = new $class(...$args);

        if ($dto instanceof Validatable) {
            $validationErrors = $dto->validate();
            if ($validationErrors !== []) {
                throw new ValidationException($validationErrors);
            }
        }

        return $dto;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected static function coerce(
        mixed $value,
        ReflectionNamedType $type,
        string $field,
        array &$errors,
    ): mixed {
        $typeName = $type->getName();

        if (enum_exists($typeName)) {
            if (!is_string($value) && !is_int($value)) {
                $errors[$field][] = "Invalid value for {$field}";
                return null;
            }

            try {
                /** @var class-string<\BackedEnum> $typeName */
                return $typeName::from($value);
            } catch (\ValueError) {
                $ref = new ReflectionClass($typeName);
                $cases = $ref->getMethod('cases')->invoke(null);
                $allowed = implode(', ', array_map(
                    static fn($c) => $c->value ?? $c->name,
                    $cases,
                ));
                $errors[$field][] = "Invalid value '{$value}'. Expected: {$allowed}";
                return null;
            }
        }

        return match ($typeName) {
            'string' => (string) $value,
            'int' => is_numeric($value) ? (int) $value : (static function () use ($field, &$errors) {
                $errors[$field][] = 'Must be an integer';
                return null;
            })(),
            'float' => is_numeric($value) ? (float) $value : (static function () use ($field, &$errors) {
                $errors[$field][] = 'Must be a number';
                return null;
            })(),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (static function () use ($field, &$errors) {
                $errors[$field][] = 'Must be a boolean';
                return null;
            })(),
            'array' => is_array($value) ? $value : (static function () use ($field, &$errors) {
                $errors[$field][] = 'Must be an array';
                return null;
            })(),
            default => $value,
        };
    }

    private static function isScopeType(string $typeName): bool
    {
        foreach (self::$scopeTypes as $scopeType) {
            if ($typeName === $scopeType || is_subclass_of($typeName, $scopeType)) {
                return true;
            }
        }

        return false;
    }

    private static function isNullableParam(ReflectionParameter $param): bool
    {
        $type = $param->getType();

        if ($type === null) {
            return true;
        }

        return $type->allowsNull();
    }
}
