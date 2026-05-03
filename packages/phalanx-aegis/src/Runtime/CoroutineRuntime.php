<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use Closure;
use OpenSwoole\Coroutine;
use RuntimeException;
use Throwable;

final class CoroutineRuntime
{
    private function __construct()
    {
    }

    /** @param Closure(): mixed $body */
    public static function run(
        RuntimePolicy $policy,
        Closure $body,
        bool $strict = true,
    ): mixed {
        RuntimeHooks::ensure($policy, $strict);

        if (Coroutine::getCid() >= 0) {
            return $body();
        }

        $result = null;
        $caught = null;
        $finished = false;

        Coroutine::run(static function () use ($body, &$result, &$caught, &$finished): void {
            try {
                $result = $body();
            } catch (Throwable $e) {
                $caught = $e;
            } finally {
                $finished = true;
            }
        });

        if ($caught !== null) {
            throw $caught;
        }

        if (!$finished) {
            throw new RuntimeException('OpenSwoole coroutine runtime did not execute the managed body.');
        }

        return $result;
    }
}
