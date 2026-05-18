<?php

declare(strict_types=1);

namespace Phalanx\Athena\Effect;

enum BuiltInKind: string
{
    case Noop = 'noop';
    case Echo = 'echo';
    case Halt = 'halt';

    public static function matches(string $effectId): bool
    {
        return self::tryFrom($effectId) !== null;
    }
}
