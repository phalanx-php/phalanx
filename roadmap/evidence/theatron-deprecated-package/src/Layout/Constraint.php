<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Layout;

enum Constraint
{
    case Length;
    case Percentage;
    case Min;
    case Max;
    case Fill;

    public static function length(int $n): ConstraintValue
    {
        return new ConstraintValue(self::Length, $n);
    }

    public static function percentage(int $p): ConstraintValue
    {
        return new ConstraintValue(self::Percentage, $p);
    }

    public static function min(int $n): ConstraintValue
    {
        return new ConstraintValue(self::Min, $n);
    }

    public static function max(int $n): ConstraintValue
    {
        return new ConstraintValue(self::Max, $n);
    }

    public static function fill(): ConstraintValue
    {
        return new ConstraintValue(self::Fill, 0);
    }
}
