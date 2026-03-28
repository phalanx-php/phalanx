<?php

declare(strict_types=1);

namespace Phalanx\Console;

final class Opt
{
    public static function flag(string $name, string $shorthand = '', string $desc = ''): CommandOption
    {
        return new CommandOption($name, $shorthand, $desc, requiresValue: false, default: false);
    }

    public static function value(string $name, string $shorthand = '', string $desc = '', mixed $default = null): CommandOption
    {
        return new CommandOption($name, $shorthand, $desc, requiresValue: true, default: $default);
    }
}
