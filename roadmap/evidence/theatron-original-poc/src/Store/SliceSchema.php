<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

final class SliceSchema
{
    public string $key {
        get => $this->initial->key;
    }

    /**
     * @param class-string<Slice> $class
     * @param list<SliceColumn> $columns
     */
    private function __construct(
        private(set) string $class,
        private Slice $initial,
        private(set) array $columns,
    ) {
    }

    /** @param class-string<Slice> $class */
    public static function from(string $class): self
    {
        $ref = new ReflectionClass($class);
        $constructor = $ref->getConstructor();
        $args = [];
        $columns = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if (!$parameter->isDefaultValueAvailable()) {
                    throw UnsupportedSliceSchema::constructor($class);
                }

                $columns[] = self::column($ref, $parameter);
                $args[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        $slice = new $class(...$args);
        if (!$slice instanceof Slice) {
            throw UnsupportedSliceSchema::constructor($class);
        }

        return new self($class, $slice, $columns);
    }

    public function initial(): Slice
    {
        return $this->initial;
    }

    /** @return array<string, int|float|string> */
    public function encode(Slice $slice): array
    {
        if (!$slice instanceof $this->class) {
            throw new StoreException(sprintf('Expected %s, got %s.', $this->class, $slice::class));
        }

        $row = [];
        foreach ($this->columns as $column) {
            $value = $slice->{$column->name};
            if ($column->nullable) {
                $row[$column->name . '__present'] = $value === null ? 0 : 1;
            }

            $row[$column->name] = self::storedValue($column, $value);
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public function hydrate(array $row): Slice
    {
        $args = [];
        foreach ($this->columns as $column) {
            if ($column->nullable && (int) ($row[$column->name . '__present'] ?? 0) === 0) {
                $args[$column->name] = null;
                continue;
            }

            $args[$column->name] = match ($column->type) {
                'int' => (int) $row[$column->name],
                'bool' => (bool) $row[$column->name],
                'float' => (float) $row[$column->name],
                'string' => (string) $row[$column->name],
            };
        }

        return new $this->class(...$args);
    }

    /** @param ReflectionClass<Slice> $class */
    private static function column(ReflectionClass $class, ReflectionParameter $parameter): SliceColumn
    {
        $type = $parameter->getType();
        $name = $parameter->getName();
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            throw UnsupportedSliceSchema::property(
                $class->name,
                $name,
                'only builtin scalar constructor properties are supported',
            );
        }

        $typeName = $type->getName();
        if (!in_array($typeName, ['int', 'float', 'string', 'bool'], true)) {
            throw UnsupportedSliceSchema::property(
                $class->name,
                $name,
                "{$typeName} is not supported by the scalar table store",
            );
        }

        if (!$class->hasProperty($name)) {
            throw UnsupportedSliceSchema::property(
                $class->name,
                $name,
                'constructor parameters must map to readable slice properties',
            );
        }

        $property = $class->getProperty($name);
        if (!$property->isPublic()) {
            throw UnsupportedSliceSchema::property(
                $class->name,
                $name,
                'slice properties must be publicly readable',
            );
        }

        return new SliceColumn($name, $typeName, $type->allowsNull());
    }

    private static function storedValue(SliceColumn $column, mixed $value): int|float|string
    {
        if ($value === null) {
            return match ($column->type) {
                'int', 'bool' => 0,
                'float' => 0.0,
                'string' => '',
            };
        }

        return match ($column->type) {
            'int' => (int) $value,
            'bool' => $value ? 1 : 0,
            'float' => (float) $value,
            'string' => self::storedString($column, $value),
        };
    }

    private static function storedString(SliceColumn $column, mixed $value): string
    {
        $string = (string) $value;
        if (strlen($string) > $column->tableSize) {
            throw new StoreException("String slice value {$column->name} exceeds {$column->tableSize} bytes.");
        }

        return $string;
    }
}
