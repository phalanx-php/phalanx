<?php

declare(strict_types=1);

namespace Phalanx\Athena\Examples\Support;

use Phalanx\Boot\AppContext;

/**
 * Produces a human-readable status table for a list of context keys.
 *
 * Reports whether each key is present, missing, or set-but-suppressed
 * because ATHENA_DEMO_LIVE is not enabled. Used by demos that print a
 * pre-flight env summary before running.
 */
final class EnvStatusReporter
{
    public function __construct(private readonly AppContext $context)
    {
    }

    /**
     * @param  list<string>              $keys       Context keys to inspect.
     * @param  list<string>              $liveKeys   Subset of $keys that require live mode.
     * @return array<string, string>                 key → status string.
     */
    public function report(array $keys, array $liveKeys = []): array
    {
        $live   = $this->context->bool(DemoContextKeys::ATHENA_DEMO_LIVE, false);
        $report = [];

        foreach ($keys as $key) {
            $present = $this->context->has($key)
                && $this->context->get($key) !== ''
                && $this->context->get($key) !== null;

            if (!$present) {
                $report[$key] = 'missing';
                continue;
            }

            if (!$live && in_array($key, $liveKeys, true)) {
                $report[$key] = 'set but ignored; set ATHENA_DEMO_LIVE=1';
                continue;
            }

            $report[$key] = 'present';
        }

        return $report;
    }
}
