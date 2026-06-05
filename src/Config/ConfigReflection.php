<?php

declare(strict_types=1);

namespace Phalanx\Config;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

final class ConfigReflection
{
    /**
     * @param class-string<Config> $type
     * @return list<ConfigDefinition>
     */
    public function describe(string $type): array
    {
        $definitions = [];
        $this->collect($type, $definitions);

        return array_values($definitions);
    }

    /**
     * @param class-string<Config> $type
     * @param array<class-string<Config>, ConfigDefinition> $definitions
     */
    private function collect(string $type, array &$definitions): void
    {
        if (isset($definitions[$type])) {
            return;
        }

        $reflection = new ReflectionClass($type);
        $constructor = $reflection->getConstructor();
        $entries = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $nested = $this->nestedConfig($parameter);
                if ($nested !== null) {
                    $this->collect($nested, $definitions);
                    continue;
                }

                $env = Env::fromParameter($parameter);
                if ($env === null) {
                    continue;
                }

                $entries[] = new ConfigEntry(
                    parameter: $parameter->getName(),
                    envKey: $env->key,
                    type: $this->typeLabel($parameter),
                    required: !$parameter->isDefaultValueAvailable()
                        && $this->typeName($parameter) !== Secret::class
                        && !($parameter->getType() instanceof ReflectionNamedType && $parameter->getType()->allowsNull()),
                    default: $this->defaultLabel($parameter),
                    description: $env->description,
                    secret: $env->secret || $this->typeName($parameter) === Secret::class,
                    group: $env->group,
                    example: $env->example,
                );
            }
        }

        $definitions[$type] = new ConfigDefinition($type, $entries);
    }

    /** @return ?class-string<Config> */
    private function nestedConfig(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $typeName = $type->getName();
        if (!is_subclass_of($typeName, Config::class)) {
            return null;
        }

        /** @var class-string<Config> $typeName */
        return $typeName;
    }

    private function typeLabel(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType) {
            return 'mixed';
        }

        $name = $type->getName();
        if ($name === Secret::class) {
            return 'secret';
        }

        if (class_exists($name) && is_subclass_of($name, BackedEnum::class)) {
            return 'enum';
        }

        return $name;
    }

    private function typeName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    private function defaultLabel(ReflectionParameter $parameter): ?string
    {
        if (!$parameter->isDefaultValueAvailable()) {
            return null;
        }

        $default = $parameter->getDefaultValue();
        if ($default instanceof Secret) {
            return null;
        }

        if ($default instanceof BackedEnum) {
            return (string) $default->value;
        }

        if (is_bool($default)) {
            return $default ? 'true' : 'false';
        }

        if ($default === null) {
            return null;
        }

        return (string) $default;
    }
}
