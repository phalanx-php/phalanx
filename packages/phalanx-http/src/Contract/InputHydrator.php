<?php

declare(strict_types=1);

namespace Phalanx\Http\Contract;

use Closure;
use Phalanx\ExecutionScope;
use Phalanx\Http\RequestScope;
use Phalanx\Http\ValidationException;
use Phalanx\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;

class InputHydrator
{
    /** @var array<string, ?InputMeta> */
    private static array $metaCache = [];

    /** @var list<class-string> */
    private static array $scopeTypes = [
        Scope::class,
        ExecutionScope::class,
        RequestScope::class,
    ];

    /**
     * Reflect on the handler's __invoke and find the typed input parameter.
     */
    public static function meta(Closure|Scopeable|Executable $handler): ?InputMeta
    {
        $key = self::cacheKey($handler);

        if (array_key_exists($key, self::$metaCache)) {
            return self::$metaCache[$key];
        }

        $ref = self::reflectInvoke($handler);
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
     * @return list<mixed>
     */
    public static function resolve(
        Closure|Scopeable|Executable $handler,
        RequestScope $scope,
    ): array {
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
            'int' => is_numeric($value) ? (int) $value : (function () use ($field, $value, &$errors) {
                $errors[$field][] = 'Must be an integer';
                return null;
            })(),
            'float' => is_numeric($value) ? (float) $value : (function () use ($field, $value, &$errors) {
                $errors[$field][] = 'Must be a number';
                return null;
            })(),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (function () use ($field, &$errors) {
                $errors[$field][] = 'Must be a boolean';
                return null;
            })(),
            'array' => is_array($value) ? $value : (function () use ($field, &$errors) {
                $errors[$field][] = 'Must be an array';
                return null;
            })(),
            default => $value,
        };
    }

    private static function reflectInvoke(Closure|Scopeable|Executable $handler): ReflectionFunctionAbstract
    {
        if ($handler instanceof Closure) {
            return new ReflectionFunction($handler);
        }

        return (new ReflectionClass($handler))->getMethod('__invoke');
    }

    private static function cacheKey(Closure|Scopeable|Executable $handler): string
    {
        if ($handler instanceof Closure) {
            $ref = new ReflectionFunction($handler);

            return $ref->getFileName() . ':' . $ref->getStartLine();
        }

        return $handler::class;
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
