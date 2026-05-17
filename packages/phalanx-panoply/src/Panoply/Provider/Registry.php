<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider;

/**
 * Immutable provider lookup registry. Each {@see self::with()} call
 * returns a new instance with the additional Config added. Bulk loading
 * strategies live in dedicated loader classes that compose with this
 * registry.
 *
 * Final — extension would change immutability semantics that hosts
 * depend on.
 */
final class Registry
{
    /**
     * @param array<string, Config> $configs keyed by provider id
     */
    public function __construct(
        private(set) array $configs = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function with(Config $config): self
    {
        foreach ($config->models as $model) {
            foreach ([$model->name, ...$model->aliases] as $alias) {
                if ($this->byModelAlias($alias) !== null) {
                    throw new DuplicateModelAlias(
                        "Model alias '{$alias}' from provider '{$config->id}' collides with an existing registration",
                    );
                }
            }
        }

        $configs              = $this->configs;
        $configs[$config->id] = $config;

        return new self($configs);
    }

    public function get(string $id): ?Config
    {
        return $this->configs[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->configs[$id]);
    }

    /**
     * @return array<string, Config>
     */
    public function all(): array
    {
        return $this->configs;
    }

    /**
     * Search every Config's models for any model whose name or aliases
     * match the supplied string. Returns the first match as a {@see Resolution},
     * or null when no match is found.
     */
    public function byModelAlias(string $alias): ?Resolution
    {
        foreach ($this->configs as $config) {
            foreach ($config->models as $model) {
                if ($model->name === $alias) {
                    return new Resolution($config, $model);
                }

                if (in_array($alias, $model->aliases, strict: true)) {
                    return new Resolution($config, $model);
                }
            }
        }

        return null;
    }
}
