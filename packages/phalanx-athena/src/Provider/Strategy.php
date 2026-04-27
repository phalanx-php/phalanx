<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

enum Strategy
{
    case Race;
    case Fallback;
    case RoundRobin;
    case Cheapest;

    public static function race(): self
    {
        return self::Race;
    }

    public static function fallback(): self
    {
        return self::Fallback;
    }

    public static function roundRobin(): self
    {
        return self::RoundRobin;
    }

    public static function cheapest(): self
    {
        return self::Cheapest;
    }
}
