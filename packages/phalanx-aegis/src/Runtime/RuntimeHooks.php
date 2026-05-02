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

    public static function ensure(RuntimePolicy $policy, bool $strict = true): int
    {
        $current = self::currentFlags();
        $missing = $policy->missingFlags($current);
        if ($missing === 0) {
            return $current;
        }

        try {
            Runtime::enableCoroutine(true, $current | $policy->requiredFlags);
        } catch (Throwable $e) {
            if ($strict) {
                throw RuntimePolicyViolation::enableFailed($policy, $missing, $e);
            }

            return self::currentFlags();
        }

        $current = self::currentFlags();
        if ($strict && !$policy->hasRequiredFlags($current)) {
            throw RuntimePolicyViolation::missingRequiredFlags($policy, $policy->missingFlags($current));
        }

        return $current;
    }
}
