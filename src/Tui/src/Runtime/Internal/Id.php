<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Internal;

final class Id
{
    public static function new(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            throw new \InvalidArgumentException('Runtime id prefix cannot be empty.');
        }

        return str_replace('.', '', uniqid($prefix . '_', true));
    }
}
