<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Harness\Support;

final class HarnessId
{
    public static function new(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            throw new \InvalidArgumentException('Harness id prefix cannot be empty.');
        }

        return $prefix . '_' . bin2hex(random_bytes(16));
    }
}
