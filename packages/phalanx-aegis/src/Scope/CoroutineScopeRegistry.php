<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use OpenSwoole\Coroutine;

/**
 * Coroutine-local scope storage backed by OpenSwoole\Coroutine::getContext().
 *
 * Critically, child coroutines spawned via Coroutine::create do NOT inherit
 * their parent's context. Every primitive that spawns must capture the current
 * scope and re-install it as the first action inside the spawned coroutine.
 *
 * Pattern:
 *   $scope = CoroutineScopeRegistry::current();
 *   Coroutine::create(static function () use ($scope, $fn): void {
 *       CoroutineScopeRegistry::install($scope);
 *       try { $fn(); } finally { CoroutineScopeRegistry::clear(); }
 *   });
 */
final class CoroutineScopeRegistry
{
    private const string KEY = '__aegis_scope';

    public static function install(Scope $scope): void
    {
        $context = Coroutine::getContext();
        if ($context === null) {
            return; // not in a coroutine; nothing to do
        }
        $context[self::KEY] = $scope;
    }

    public static function current(): ?Scope
    {
        $context = Coroutine::getContext();
        if ($context === null) {
            return null;
        }
        return $context[self::KEY] ?? null;
    }

    public static function clear(): void
    {
        $context = Coroutine::getContext();
        if ($context === null) {
            return;
        }
        unset($context[self::KEY]);
    }
}
