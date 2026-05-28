<?php

declare(strict_types=1);

namespace AegisSwoole\Tests;

use Closure;
use Throwable;

final class Assertions
{
    public static function equals(mixed $expected, mixed $actual, string $what = ''): ?string
    {
        if ($expected === $actual) {
            return null;
        }
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        return ($what === '' ? '' : "{$what}: ") . "expected {$e}, got {$a}";
    }

    public static function notEquals(mixed $expected, mixed $actual, string $what = ''): ?string
    {
        if ($expected !== $actual) {
            return null;
        }
        $e = var_export($expected, true);
        return ($what === '' ? '' : "{$what}: ") . "expected anything other than {$e}, got the same";
    }

    public static function same(object $expected, object $actual, string $what = ''): ?string
    {
        if ($expected === $actual) {
            return null;
        }
        return ($what === '' ? '' : "{$what}: ") . 'expected same instance, got different';
    }

    public static function notSame(object $expected, object $actual, string $what = ''): ?string
    {
        if ($expected !== $actual) {
            return null;
        }
        return ($what === '' ? '' : "{$what}: ") . 'expected different instances, got same';
    }

    /**
     * Run $fn and assert that it throws an instance of $exceptionClass.
     * Returns null on success or a reason string on failure.
     *
     * @param class-string<Throwable> $exceptionClass
     * @param Closure(): mixed $fn
     */
    public static function throws(string $exceptionClass, Closure $fn, string $what = ''): ?string
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if ($e instanceof $exceptionClass) {
                return null;
            }
            $got = $e::class;
            return ($what === '' ? '' : "{$what}: ") . "expected {$exceptionClass}, got {$got}: {$e->getMessage()}";
        }
        return ($what === '' ? '' : "{$what}: ") . "expected {$exceptionClass} to be thrown, nothing was";
    }

    public static function elapsedBetween(float $startedAt, float $minSeconds, float $maxSeconds, string $what = ''): ?string
    {
        $elapsed = microtime(true) - $startedAt;
        if ($elapsed >= $minSeconds && $elapsed <= $maxSeconds) {
            return null;
        }
        $msg = sprintf('elapsed %.3fs not in [%.3f, %.3f]', $elapsed, $minSeconds, $maxSeconds);
        return ($what === '' ? '' : "{$what}: ") . $msg;
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function arrayEquals(array $expected, array $actual, string $what = ''): ?string
    {
        if ($expected === $actual) {
            return null;
        }
        $e = (string) json_encode($expected);
        $a = (string) json_encode($actual);
        return ($what === '' ? '' : "{$what}: ") . "expected {$e}, got {$a}";
    }
}
