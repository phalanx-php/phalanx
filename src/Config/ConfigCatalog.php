<?php

declare(strict_types=1);

namespace Phalanx\Config;

use LogicException;
use ReflectionClass;
use ReflectionNamedType;

final class ConfigCatalog
{
    /** @var ?list<CatalogNode> */
    private ?array $cachedTree = null;

    /** @var ?list<ConfigDefinition> */
    private ?array $cachedDefinitions = null;

    /** @var ?list<class-string<Config>> */
    private ?array $cachedClasses = null;

    /** @param list<class-string<Config>> $roots */
    private function __construct(private(set) array $roots)
    {
    }

    /** @param class-string<Config> ...$roots */
    public static function of(string ...$roots): self
    {
        return new self(array_values($roots));
    }

    /** @return list<CatalogNode> */
    public function tree(): array
    {
        if ($this->cachedTree !== null) {
            return $this->cachedTree;
        }

        $nodes = [];

        foreach ($this->roots as $root) {
            $shortName = (new ReflectionClass($root))->getShortName();
            $nodes[] = $this->buildNode($root, $shortName, []);
        }

        return $this->cachedTree = $nodes;
    }

    /** @return list<ConfigDefinition> */
    public function definitions(): array
    {
        if ($this->cachedDefinitions !== null) {
            return $this->cachedDefinitions;
        }

        $reflection = new ConfigReflection();
        $definitions = [];
        $seen = [];

        foreach ($this->roots as $root) {
            foreach ($reflection->describe($root) as $definition) {
                if (isset($seen[$definition->type])) {
                    continue;
                }

                $seen[$definition->type] = true;
                $definitions[] = $definition;
            }
        }

        return $this->cachedDefinitions = $definitions;
    }

    /**
     * @return list<class-string<Config>>
     */
    public function classes(): array
    {
        if ($this->cachedClasses !== null) {
            return $this->cachedClasses;
        }

        $seen = [];

        foreach ($this->roots as $root) {
            $this->collectClasses($root, $seen, []);
        }

        /** @var list<class-string<Config>> */
        return $this->cachedClasses = array_keys($seen);
    }

    /**
     * @param class-string<Config> $type
     * @param list<class-string<Config>> $ancestors
     */
    private function buildNode(string $type, string $path, array $ancestors): CatalogNode
    {
        if (in_array($type, $ancestors, true)) {
            throw new LogicException(
                "Cycle detected in config tree: {$type} appears in its own ancestor chain at path '{$path}'.",
            );
        }

        $reflection = new ReflectionClass($type);
        $constructor = $reflection->getConstructor();
        $entries = [];
        $children = [];
        $nextAncestors = [...$ancestors, $type];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $paramType = $parameter->getType();

                if (!$paramType instanceof ReflectionNamedType || $paramType->isBuiltin()) {
                    $env = Env::fromParameter($parameter);
                    if ($env !== null) {
                        $entries[] = new ConfigEntry(
                            parameter: $parameter->getName(),
                            envKey: $env->key,
                            type: $paramType instanceof ReflectionNamedType ? $paramType->getName() : 'mixed',
                            required: !$parameter->isDefaultValueAvailable()
                                && !($paramType instanceof ReflectionNamedType && $paramType->allowsNull()),
                            default: $parameter->isDefaultValueAvailable()
                                ? (is_bool($parameter->getDefaultValue())
                                    ? ($parameter->getDefaultValue() ? 'true' : 'false')
                                    : (string) $parameter->getDefaultValue())
                                : null,
                            description: $env->description,
                            secret: $env->secret,
                            group: $env->group,
                            example: $env->example,
                        );
                    }

                    continue;
                }

                $typeName = $paramType->getName();

                if (!is_subclass_of($typeName, Config::class)) {
                    $env = Env::fromParameter($parameter);
                    if ($env !== null) {
                        $entries[] = new ConfigEntry(
                            parameter: $parameter->getName(),
                            envKey: $env->key,
                            type: $typeName,
                            required: !$parameter->isDefaultValueAvailable() && !$paramType->allowsNull(),
                            default: $parameter->isDefaultValueAvailable() ? (string) $parameter->getDefaultValue() : null,
                            description: $env->description,
                            secret: $env->secret,
                            group: $env->group,
                            example: $env->example,
                        );
                    }

                    continue;
                }

                /** @var class-string<Config> $typeName */
                $childPath = $path . '.' . $parameter->getName();
                $children[] = $this->buildNode($typeName, $childPath, $nextAncestors);
            }
        }

        return new CatalogNode(
            type: $type,
            path: $path,
            entries: $entries,
            children: $children,
        );
    }

    /**
     * @param class-string<Config> $type
     * @param array<class-string<Config>, true> $seen
     * @param list<class-string<Config>> $ancestors
     */
    private function collectClasses(string $type, array &$seen, array $ancestors): void
    {
        if (in_array($type, $ancestors, true)) {
            return;
        }

        $seen[$type] = true;
        $reflection = new ReflectionClass($type);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return;
        }

        $nextAncestors = [...$ancestors, $type];

        foreach ($constructor->getParameters() as $parameter) {
            $paramType = $parameter->getType();

            if (!$paramType instanceof ReflectionNamedType || $paramType->isBuiltin()) {
                continue;
            }

            $typeName = $paramType->getName();

            if (!is_subclass_of($typeName, Config::class)) {
                continue;
            }

            /** @var class-string<Config> $typeName */
            if (!isset($seen[$typeName])) {
                $this->collectClasses($typeName, $seen, $nextAncestors);
            }
        }
    }
}
