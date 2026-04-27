<?php

declare(strict_types=1);

namespace Phalanx\Support;

final class ClassNames
{
    public static function short(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
