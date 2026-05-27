<?php

declare(strict_types=1);

namespace Phalanx\Dory\Rendering;

final class ScalarRenderer implements ValueRenderer
{
    public function supports(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null;
    }

    public function render(mixed $value, OutputSink $output): void
    {
        if (is_string($value)) {
            $output->line($value);
            return;
        }

        if (is_bool($value)) {
            $output->line($value ? 'true' : 'false');
            return;
        }

        if ($value === null) {
            $output->line('null');
            return;
        }

        $output->line((string) $value);
    }
}
