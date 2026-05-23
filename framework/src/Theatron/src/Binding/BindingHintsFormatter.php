<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Binding;

use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Tdom\Element\RowElement;
use function Phalanx\Theatron\Ui\row;
use function Phalanx\Theatron\Ui\text;

final class BindingHintsFormatter
{
    /** @param list<Binding> $bindings */
    public static function render(array $bindings): RowElement
    {
        if ($bindings === []) {
            return row();
        }

        $children = [];
        $last = count($bindings) - 1;

        foreach ($bindings as $i => $binding) {
            $label = $binding->label;

            if ($label === null || $label === '') {
                continue;
            }

            $combo = self::formatCombo($binding);

            $children[] = text('[muted]' . $combo . '[/]');
            $children[] = text(' [hint]' . $label . '[/]');

            if ($i < $last) {
                $children[] = text('  ');
            }
        }

        return row(...$children);
    }

    public static function formatCombo(Binding $binding): string
    {
        $parts = [];

        if ($binding->ctrl) {
            $parts[] = 'Ctrl';
        }

        if ($binding->alt) {
            $parts[] = 'Alt';
        }

        if ($binding->shift) {
            $parts[] = 'Shift';
        }

        $parts[] = self::formatKey($binding->key);

        return implode('+', $parts);
    }

    private static function formatKey(Key|string $key): string
    {
        if ($key instanceof Key) {
            return match (true) {
                str_starts_with($key->value, 'f') && ctype_digit(substr($key->value, 1)) => strtoupper($key->value),
                default => $key->name,
            };
        }

        return $key;
    }
}
