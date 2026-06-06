<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Internal;

final class StringList
{
    /**
     * @param list<string> $values
     * @return list<string>
     */
    public static function unique(array $values): array
    {
        $out = [];
        $cache = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '' || isset($cache[$value])) {
                continue;
            }

            $out[] = $value;
            $cache[$value] = true;
        }

        return $out;
    }
}
