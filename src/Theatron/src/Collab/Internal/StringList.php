<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Internal;

final class StringList
{
    /**
     * @param list<string> $values
     * @return list<string>
     */
    public static function unique(array $values): array
    {
        $seen = [];
        $out = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $out[] = $value;
        }

        return $out;
    }
}
