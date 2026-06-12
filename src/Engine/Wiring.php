<?php

declare(strict_types=1);

namespace Phalanx\Engine;

use LogicException;
use Phalanx\Invocation\Caps;
use Phalanx\Scope\Scope;

/**
 * Engine-internal Caps assembly seam. Factories receive the FRAME scope so
 * the Scope grant (B7) is engine-injected, never ambient. Boot plans replace
 * these internals at the engine-lifecycle rows; tasks never see this surface.
 */
final class Wiring
{
    /** @var array<class-string<Caps>, callable(Scope): Caps> */
    private array $factories = [];

    /**
     * @template T of Caps
     *
     * @param class-string<T> $capsClass
     * @param callable(Scope): T $factory
     */
    public function provide(string $capsClass, callable $factory): void
    {
        $this->factories[$capsClass] = $factory;
    }

    /**
     * @param class-string<Caps> $capsClass
     */
    public function supply(string $capsClass, Scope $frame): Caps
    {
        $factory = $this->factories[$capsClass] ?? null;

        if ($factory === null) {
            throw new LogicException(sprintf('No wiring supplies %s.', $capsClass));
        }

        return $factory($frame);
    }
}
