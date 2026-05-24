<?php

declare(strict_types=1);

namespace Phalanx\Testing\Fakes;

use InvalidArgumentException;

/**
 * Per-test override map for service bindings.
 *
 * TestApp consults the registry when resolving services; a registered
 * fake is returned in place of the bundle-provided binding. Fakes are
 * cleared by TestApp::reset() between tests.
 *
 * The registry holds object instances only — fake classes are
 * instantiated by the caller (or by a lens-specific helper) before
 * registration. This keeps the registry free of construction policy
 * and lets each fake decide its own surface (recording, asserting,
 * etc.).
 */
final class FakeRegistry
{
    /** @var array<class-string, object> */
    private array $bindings = [];

    /**
     * @template T of object
     * @param class-string<T> $service
     * @param T               $fake
     */
    public function register(string $service, object $fake): void
    {
        if (!$fake instanceof $service) {
            throw new InvalidArgumentException(
                "Fake for {$service} must be an instance of that type; got " . $fake::class . '.',
            );
        }

        $this->bindings[$service] = $fake;
    }

    /** @param class-string $service */
    public function has(string $service): bool
    {
        return isset($this->bindings[$service]);
    }

    /**
     * @template T of object
     * @param class-string<T> $service
     * @return T|null
     */
    public function get(string $service): ?object
    {
        /** @var T|null */
        return $this->bindings[$service] ?? null;
    }

    /** @return array<class-string, object> */
    public function all(): array
    {
        return $this->bindings;
    }

    public function reset(): void
    {
        $this->bindings = [];
    }
}
