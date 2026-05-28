<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Service;

use Phalanx\Swoole\Mvp\Runtime\CompileException;

final class Container
{
    /** @var array<class-string, object> */
    private array $instances = [];

    /**
     * @param array<class-string, ResourceDescriptor> $resources
     */
    public function __construct(private readonly array $resources) {}

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object
    {
        if (isset($this->instances[$class])) {
            /** @var T */
            return $this->instances[$class];
        }
        $descriptor = $this->resources[$class] ?? throw new CompileException(
            "Resource {$class} is not registered."
        );
        if ($descriptor->factory === null) {
            throw new CompileException("Resource {$class} has no factory.");
        }
        $instance = ($descriptor->factory)();
        if (! $instance instanceof $class) {
            throw new CompileException(
                "Factory for {$class} returned " . $instance::class . '.'
            );
        }
        return $this->instances[$class] = $instance;
    }

    /**
     * @return array<class-string, ResourceDescriptor>
     */
    public function descriptors(): array
    {
        return $this->resources;
    }
}
