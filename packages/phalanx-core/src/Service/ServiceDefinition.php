<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Closure;
use Phalanx\Lifecycle\LifecycleCallbacks;
use Phalanx\Support\ClassNames;

final class ServiceDefinition
{
    /**
     * @param class-string $type
     * @param list<string> $implements
     * @param list<string> $tags
     * @param list<string> $dependencies
     */
    public function __construct(
        public private(set) string $type,
        public private(set) array $implements = [],
        public private(set) array $tags = [],
        public private(set) array $dependencies = [],
        public private(set) ?Closure $factory = null,
        public private(set) bool $singleton = true,
        public private(set) bool $lazy = true,
        public private(set) LifecycleCallbacks $lifecycle = new LifecycleCallbacks(),
    ) {
    }

    public function withFactory(Closure $factory): self
    {
        $clone = clone $this;
        $clone->factory = $factory;
        return $clone;
    }

    public function withDependencies(string ...$deps): self
    {
        $clone = clone $this;
        $clone->dependencies = array_values([...$this->dependencies, ...$deps]);
        return $clone;
    }

    public function withTags(string ...$tags): self
    {
        $clone = clone $this;
        $clone->tags = array_values([...$this->tags, ...$tags]);
        return $clone;
    }

    public function withImplements(string ...$interfaces): self
    {
        $clone = clone $this;
        $clone->implements = array_values([...$this->implements, ...$interfaces]);
        return $clone;
    }

    public function asSingleton(): self
    {
        $clone = clone $this;
        $clone->singleton = true;
        return $clone;
    }

    public function asScoped(): self
    {
        $clone = clone $this;
        $clone->singleton = false;
        return $clone;
    }

    public function asLazy(): self
    {
        $clone = clone $this;
        $clone->lazy = true;
        return $clone;
    }

    public function asEager(): self
    {
        $clone = clone $this;
        $clone->lazy = false;
        return $clone;
    }

    public function withLifecycleHook(string $phase, Closure $hook): self
    {
        $clone = clone $this;
        $clone->lifecycle = $this->lifecycle->withHook($phase, $hook);
        return $clone;
    }

    public function withLifecycle(LifecycleCallbacks $lifecycle): self
    {
        $clone = clone $this;
        $clone->lifecycle = $lifecycle;
        return $clone;
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function shortName(): string
    {
        return ClassNames::short($this->type);
    }
}
