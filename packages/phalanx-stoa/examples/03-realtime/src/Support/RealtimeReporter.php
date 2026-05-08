<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Support;

final readonly class RealtimeReporter
{
    public function __invoke(string $label, bool $passed): bool
    {
        echo '  ' . ($passed ? 'ok    ' : 'FAIL  ') . $label . PHP_EOL;

        return $passed;
    }
}
