<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Reactive;

use RuntimeException;

final class Tracker
{
    private const string KEY = '__tui_tracker';

    /** @var list<array<int, object>> */
    private static array $fallbackStack = [];

    public static function push(): int
    {
        $stack = self::getStack();
        $stack[] = [];
        self::setStack($stack);

        return count($stack) - 1;
    }

    /** @return list<object> */
    public static function pop(int $frame): array
    {
        $stack = self::getStack();

        if (!array_key_exists($frame, $stack)) {
            throw new RuntimeException(sprintf(
                'Tracker::pop() frame %d is invalid (stack depth: %d).',
                $frame,
                count($stack),
            ));
        }

        $deps = array_values($stack[$frame]);
        array_splice($stack, $frame);
        self::setStack($stack);

        return $deps;
    }

    public static function recordAccess(object $reactive): void
    {
        $stack = self::getStack();

        if ($stack === []) {
            return;
        }

        $top = array_key_last($stack);
        $stack[$top][spl_object_id($reactive)] = $reactive;
        self::setStack($stack);
    }

    public static function isTracking(): bool
    {
        return self::getStack() !== [];
    }

    /** @return list<array<int, object>> */
    private static function getStack(): array
    {
        if (self::inCoroutine()) {
            return \Swoole\Coroutine::getContext()[self::KEY] ?? [];
        }

        return self::$fallbackStack;
    }

    /** @param list<array<int, object>> $stack */
    private static function setStack(array $stack): void
    {
        if (self::inCoroutine()) {
            \Swoole\Coroutine::getContext()[self::KEY] = $stack;

            return;
        }

        self::$fallbackStack = $stack;
    }

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && \Swoole\Coroutine::getCid() > 0;
    }
}
