<?php

declare(strict_types=1);

namespace Phalanx\Dory\Rendering;

use Phalanx\Concurrency\Settlement;
use Phalanx\Concurrency\SettlementBag;

final class SettlementRenderer implements ValueRenderer
{
    public function supports(mixed $value): bool
    {
        return $value instanceof SettlementBag;
    }

    public function render(mixed $value, OutputSink $output): void
    {
        if (!$value instanceof SettlementBag) {
            return;
        }

        $total = $value->count();
        $okCount = count($value->okKeys);

        $output->line($this->summary($total, $okCount, $value->allOk, $value->allErr));

        foreach ($value as $key => $settlement) {
            $output->line($this->settlementLine($key, $settlement));
        }
    }

    private function summary(int $total, int $okCount, bool $allOk, bool $allErr): string
    {
        if ($allOk) {
            return "all {$total} succeeded";
        }

        if ($allErr) {
            return "all {$total} failed";
        }

        return "{$okCount}/{$total} succeeded";
    }

    private function settlementLine(string|int $key, Settlement $settlement): string
    {
        $status = $settlement->isOk ? 'ok' : 'err';

        if ($settlement->isOk) {
            $preview = is_scalar($settlement->value)
                ? (string) $settlement->value
                : var_export($settlement->value, true);

            return "  [{$key}] {$status}: {$preview}";
        }

        $message = $settlement->error !== null ? $settlement->error->getMessage() : '(no error)';

        return "  [{$key}] {$status}: {$message}";
    }
}
