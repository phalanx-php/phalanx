<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Fiber;
use Phalanx\ExecutionScope;
use WeakMap;

final class FiberScopeRegistry
{
    /** @var WeakMap<object, ExecutionScope> */
    private static WeakMap $scopes;

    /** Last-write-wins — only one main-thread scope active at a time. */
    private static ?ExecutionScope $mainScope = null;

    private static bool $initialized = false;

    public static function register(ExecutionScope $scope): void
    {
        self::init();

        $fiber = Fiber::getCurrent();

        if (!$fiber instanceof \Fiber) {
            self::$mainScope = $scope;
            return;
        }

        self::$scopes[$fiber] = $scope;
    }

    public static function unregister(): void
    {
        self::init();

        $fiber = Fiber::getCurrent();

        if (!$fiber instanceof \Fiber) {
            self::$mainScope = null;
            return;
        }

        unset(self::$scopes[$fiber]);
    }

    public static function current(): ?ExecutionScope
    {
        self::init();

        $fiber = Fiber::getCurrent();

        if (!$fiber instanceof \Fiber) {
            return self::$mainScope;
        }

        /** Fallback: fibers spawned outside execute() lack a registered scope. */
        return self::$scopes[$fiber] ?? self::$mainScope;
    }

    public static function reset(): void
    {
        self::$scopes = new WeakMap();
        self::$mainScope = null;
        self::$initialized = true;
    }

    private static function init(): void
    {
        if (!self::$initialized) {
            self::$scopes = new WeakMap();
            self::$initialized = true;
        }
    }
}
