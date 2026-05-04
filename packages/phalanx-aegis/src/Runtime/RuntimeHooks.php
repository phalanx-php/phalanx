<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use OpenSwoole\Coroutine;
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
        self::applyCoroutineOptions($policy);

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

    /**
     * Push coroutine-level options (`use_fiber_context`, etc.) into
     * OpenSwoole. This is best-effort: Coroutine::set is idempotent and
     * safe to call before any coroutine is spawned. Failures are
     * swallowed because none of the options here are correctness-
     * critical at this seam — they are observability/compatibility
     * upgrades. Any environmental misconfiguration is surfaced through
     * the EnvironmentDoctor instead.
     *
     * `use_fiber_context` cannot be changed while coroutines are running
     * (OpenSwoole emits a PHP warning and refuses). Phalanx applies the
     * setting at boot, so the active-coroutine guard short-circuits when
     * called from inside an already-live coroutine context (e.g. nested
     * test harnesses), keeping ensure() warning-free.
     */
    private static function applyCoroutineOptions(RuntimePolicy $policy): void
    {
        $stats = Coroutine::stats();
        if ((int) ($stats['coroutine_num'] ?? 0) > 0) {
            return;
        }
        try {
            Coroutine::set($policy->coroutineOptions());
        } catch (Throwable) {
            // Older OpenSwoole builds may not recognize newer options;
            // ignore and let inspect()/ensure() drive the policy gate.
        }
    }
}
