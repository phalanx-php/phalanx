<?php

declare(strict_types=1);

namespace Phalanx\Engine;

use LogicException;
use Phalanx\Invocation\Caps;
use Phalanx\Invocation\Executable;
use Phalanx\Supervision\Operation;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The kernel's cached view of a work unit's __invoke: the in-process
 * precursor of boot plans. Reflected once per class.
 */
final class InvokeSignature
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    /** @param class-string<Caps>|null $capsClass */
    private function __construct(
        private(set) ?string $capsClass,
        private(set) ?string $operation,
    ) {
    }

    /** @param Executable<mixed> $work */
    public static function of(Executable $work): self
    {
        return self::$cache[$work::class] ??= self::reflect($work);
    }

    /** @param Executable<mixed> $work */
    private static function reflect(Executable $work): self
    {
        $reflection = new ReflectionClass($work);

        if (!$reflection->hasMethod('__invoke')) {
            throw new LogicException(sprintf('%s must declare __invoke to be dispatched.', $work::class));
        }

        $parameters = $reflection->getMethod('__invoke')->getParameters();

        $capsClass = null;

        if (isset($parameters[1])) {
            $type = $parameters[1]->getType();

            if (!$type instanceof ReflectionNamedType || !is_subclass_of($type->getName(), Caps::class)) {
                throw new LogicException(
                    sprintf('%s::__invoke second parameter must be a Caps class.', $work::class),
                );
            }

            /** @var class-string<Caps> $capsClass */
            $capsClass = $type->getName();
        }

        $attributes = $reflection->getAttributes(Operation::class);
        $operation = $attributes === [] ? null : $attributes[0]->newInstance()->name;

        return new self($capsClass, $operation);
    }
}
