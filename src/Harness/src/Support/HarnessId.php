<?php

declare(strict_types=1);

namespace Phalanx\Harness\Support;

use Phalanx\Panoply\Id;

final class HarnessId
{
    public static function new(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            throw new \InvalidArgumentException('Harness id prefix cannot be empty.');
        }

        return $prefix . '_' . Id::generate();
    }
}
