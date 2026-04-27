<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Closure;
use Phalanx\Support\ClassNames;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use ReflectionClass;

final class LazyFactory
{
    /** @param class-string $type */
    public static function wrap(string $type, Closure $factory, Trace $trace): object
    {
        /** @var \ReflectionClass<object> $ref */
        $ref = new ReflectionClass($type);

        /**
         * final/internal/interface/abstract cannot have lazy ghosts — falls back to eager.
         *
         * @see https://www.php.net/manual/en/reflectionclass.newlazyghost.php
         */
        if ($ref->isFinal() || $ref->isInternal() || $ref->isInterface() || $ref->isAbstract()) {
            $trace->log(TraceType::ServiceInit, ClassNames::short($type));
            return $factory();
        }

        return $ref->newLazyGhost(static function (object $ghost) use ($factory, $type, $trace, $ref): void {
            $real = $factory();

            $trace->log(TraceType::ServiceInit, ClassNames::short($type));

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
