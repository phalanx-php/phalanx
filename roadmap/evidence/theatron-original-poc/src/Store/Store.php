<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

final class Store
{
    private function __construct()
    {
    }

    /** @param class-string<Slice> ...$slices */
    public static function concurrent(string $name, string ...$slices): StoreDefinition
    {
        return new StoreDefinition($name, StoreStrategy::Concurrent, $slices);
    }

    /** @param class-string<Slice> ...$slices */
    public static function parallel(string $name, string ...$slices): StoreDefinition
    {
        return new StoreDefinition($name, StoreStrategy::Parallel, $slices);
    }

    /** @param class-string<Slice> ...$slices */
    public static function memory(string $name, string ...$slices): StoreDefinition
    {
        return new StoreDefinition($name, StoreStrategy::Memory, $slices);
    }
}
