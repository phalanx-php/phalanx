<?php

declare(strict_types=1);

namespace Convoy\Service;

use Closure;
use Convoy\Support\ClassNames;
use Convoy\Trace\Trace;
use Convoy\Trace\TraceType;
use ReflectionClass;

final class LazyFactory
{
    /** @param class-string $type */
    public static function wrap(string $type, Closure $factory, Trace $trace): object
    {
        /** @var \ReflectionClass<object> $ref */
        $ref = new ReflectionClass($type);

        if ($ref->isFinal() || $ref->isInternal() || $ref->isInterface() || $ref->isAbstract()) {
            $trace->log(TraceType::ServiceInit, ClassNames::short($type));
            return $factory();
        }

        return $ref->newLazyGhost(static function (object $ghost) use ($factory, $type, $trace, $ref): void {
            $trace->log(TraceType::ServiceInit, ClassNames::short($type));

            $real = $factory();

            foreach ($ref->getProperties() as $prop) {
                if ($prop->isStatic()) {
                    continue;
                }

                if (!$prop->isInitialized($real)) {
                    continue;
                }

                $prop->setValue($ghost, $prop->getValue($real));
            }
        });
    }

    public static function isUninitialized(object $obj): bool
    {
        $ref = new ReflectionClass($obj);
        return $ref->isUninitializedLazyObject($obj);
    }

    public static function initializeIfLazy(object $obj): object
    {
        $ref = new ReflectionClass($obj);

        if ($ref->isUninitializedLazyObject($obj)) {
            $ref->initializeLazyObject($obj);
        }

        return $obj;
    }
}
