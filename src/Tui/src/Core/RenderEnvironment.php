<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Closure;
use Phalanx\Tui\Styles\Theme;
use RuntimeException;

final class RenderEnvironment
{
    private const string KEY = '__tui_render_environment';

    /** @var list<Theme> */
    private static array $fallbackStack = [];

    public static function push(Theme $theme): int
    {
        $stack = self::getStack();
        $stack[] = $theme;
        self::setStack($stack);

        return count($stack) - 1;
    }

    public static function pop(int $frame): void
    {
        $stack = self::getStack();

        if (!array_key_exists($frame, $stack)) {
            throw new RuntimeException(sprintf(
                'RenderEnvironment::pop() frame %d is invalid (stack depth: %d).',
                $frame,
                count($stack),
            ));
        }

        array_splice($stack, $frame);
        self::setStack($stack);
    }

    public static function theme(): ?Theme
    {
        $stack = self::getStack();

        if ($stack === []) {
            return null;
        }

        return $stack[array_key_last($stack)];
    }

    public static function withTheme(Theme $theme, Closure $callback): mixed
    {
        $frame = self::push($theme);

        try {
            return $callback();
        } finally {
            self::pop($frame);
        }
    }

    /** @return list<Theme> */
    private static function getStack(): array
    {
        if (self::inCoroutine()) {
            return \Swoole\Coroutine::getContext()[self::KEY] ?? [];
        }

        return self::$fallbackStack;
    }

    /** @param list<Theme> $stack */
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
