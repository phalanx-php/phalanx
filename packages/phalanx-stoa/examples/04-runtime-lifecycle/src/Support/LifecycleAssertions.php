<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

/**
 * Collects pass/fail assertion results for a demo run, replacing the mutable
 * `bool &$failed` reference pattern with an explicit accumulator.
 */
final class LifecycleAssertions
{
    private bool $failed = false;

    public function record(string $label, bool $passed): bool
    {
        echo '  ' . ($passed ? 'ok' : 'failed') . "  {$label}" . PHP_EOL;

        if (!$passed) {
            $this->failed = true;
        }

        return $passed;
    }

    public function hasFailures(): bool
    {
        return $this->failed;
    }
}
