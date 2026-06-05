<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Reactive;

use WeakMap;

final class SignalRegistry
{
    /** @var WeakMap<Signal, SignalMeta> */
    private WeakMap $signals;

    public function __construct()
    {
        $this->signals = new WeakMap();
    }

    public function register(Signal $signal, string $label): void
    {
        $this->signals[$signal] = new SignalMeta($label);
    }

    public function count(): int
    {
        return count($this->signals);
    }

    /** @return list<SignalSnapshot> */
    public function snapshot(): array
    {
        $entries = [];

        foreach ($this->signals as $signal => $meta) {
            $entries[] = new SignalSnapshot(
                label: $meta->label,
                value: self::formatValue($signal->get()),
                subscriberCount: $signal->subscriberCount,
                isDisposed: $signal->isDisposed,
            );
        }

        return $entries;
    }

    private static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $truncated = strlen($value) > 40 ? substr($value, 0, 37) . '...' : $value;

            return "\"{$truncated}\"";
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        if (is_object($value)) {
            $class = $value::class;
            $pos = strrpos($class, '\\');
            $short = $pos !== false ? substr($class, $pos + 1) : $class;

            return $short . '{}';
        }

        return get_debug_type($value);
    }
}
