<?php

declare(strict_types=1);

namespace Phalanx\Dory;

final class ScriptContextHolder
{
    private static ?ScriptContext $context = null;

    public static function set(ScriptContext $context): void
    {
        if (self::$context !== null) {
            throw new \LogicException('ScriptContextHolder::set() called while a context is already active. Concurrent script execution is not supported.');
        }

        self::$context = $context;
    }

    public static function clear(): void
    {
        self::$context = null;
    }

    public static function current(): ScriptContext
    {
        if (self::$context === null) {
            throw new \LogicException('dory() called outside of a script context. This function is only available inside scripts executed by Dory.');
        }

        return self::$context;
    }
}
