<?php

declare(strict_types=1);

namespace Phalanx\Support;

use Closure;
use ReflectionFunction;
use ReflectionNamedType;

final class ClosureInspector
{
    /**
     * Returns the fully-qualified class names of all typed, non-builtin
     * parameters in the given closure, in declaration order.
     *
     * Parameters typed as built-ins (int, string, array, etc.) or untyped
     * are excluded — only class/interface types are returned since those are
     * the only ones the DI container can resolve.
     *
     * @return list<class-string>
     */
    public static function classParameters(Closure $closure): array
    {
        $rf = new ReflectionFunction($closure);
        $types = [];

        foreach ($rf->getParameters() as $param) {
            $type = $param->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            if ($type->isBuiltin()) {
                continue;
            }

            $types[] = $type->getName();
        }

        return $types;
    }
}
