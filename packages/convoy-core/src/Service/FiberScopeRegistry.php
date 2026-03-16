<?php

declare(strict_types=1);

namespace Convoy\Service;

use Convoy\ExecutionScope;
use Fiber;
use WeakMap;

final class FiberScopeRegistry
{
    /** @var WeakMap<object, ExecutionScope> */
    private static WeakMap $scopes;

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
