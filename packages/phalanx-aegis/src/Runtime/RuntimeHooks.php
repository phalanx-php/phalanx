<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use OpenSwoole\Runtime;
use Throwable;

final class RuntimeHooks
{
    private function __construct()
    {
    }

    public static function currentFlags(): int
    {
        return Runtime::getHookFlags();
    }

    public static function inspect(RuntimePolicy $policy): RuntimeHookSnapshot
    {
        return RuntimeHookSnapshot::capture($policy);
    }

    public static function ensure(RuntimePolicy $policy, bool $strict = true): int
    {
        $before = self::inspect($policy);
        if ($before->isHealthy()) {
            return $before->currentFlags;
        }

        try {
            Runtime::enableCoroutine(true, $before->currentFlags | $policy->requiredFlags);
        } catch (Throwable $e) {
            if ($strict) {
                throw RuntimePolicyViolation::enableFailed($policy, $before->missingFlags, $e);
            }

            return self::currentFlags();
        }

        $after = self::inspect($policy);
        if ($strict && !$after->isHealthy()) {
            throw RuntimePolicyViolation::missingRequiredFlags($policy, $after->missingFlags);
        }

        return $after->currentFlags;
    }
}
