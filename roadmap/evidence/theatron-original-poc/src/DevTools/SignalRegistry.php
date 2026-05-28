<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Reactive\Signal;
use WeakMap;

final class SignalRegistry
{
    private static ?self $instance = null;
    private static bool $enabled = false;

    /** @var WeakMap<Signal, SignalMeta> */
    private WeakMap $signals;

    private function __construct()
    {
        $this->signals = new WeakMap();
    }

    public static function enable(): void
    {
        self::$enabled = true;

        if (self::$instance === null) {
            self::$instance = new self();
        }
    }

    public static function disable(): void
    {
        self::$enabled = false;
        self::$instance = null;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function register(Signal $signal, string $label): void
    {
        if (!self::$enabled || self::$instance === null) {
            return;
        }

        self::$instance->signals[$signal] = new SignalMeta($label);
    }

    /** @return list<SignalSnapshot> */
    public static function snapshot(): array
    {
        if (self::$instance === null) {
            return [];
        }

        $entries = [];

        foreach (self::$instance->signals as $signal => $meta) {
            $entries[] = new SignalSnapshot(
                label: $meta->label,
                value: self::formatValue($signal->value),
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
            $truncated = mb_strlen($value) > 40 ? mb_substr($value, 0, 37) . '...' : $value;

            return "\"{$truncated}\"";
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        if (is_object($value)) {
            $class = $value::class;
            $short = substr(strrchr($class, '\\') ?: $class, 1);

            return $short . '{}';
        }

        return get_debug_type($value);
    }
}
