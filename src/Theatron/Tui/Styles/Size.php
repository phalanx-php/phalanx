<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Styles;

final class Size
{
    private static ?self $fillInstance = null;

    private function __construct(
        private(set) SizeKind $kind,
        private(set) int $value = 0,
        private(set) int $max = 0,
    ) {
    }

    public static function fill(): self
    {
        return self::$fillInstance ??= new self(SizeKind::Fill);
    }

    public static function fr(int $n): self
    {
        return new self(SizeKind::Fractional, $n);
    }

    public static function fixed(int $n): self
    {
        return new self(SizeKind::Fixed, $n);
    }

    public static function percent(int $p): self
    {
        return new self(SizeKind::Percent, $p);
    }

    public static function between(int $min, int $max): self
    {
        return new self(SizeKind::Between, $min, $max);
    }
}
