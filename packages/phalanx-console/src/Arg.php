<?php

declare(strict_types=1);

namespace Phalanx\Console;

final class Arg
{
    public static function required(string $name, string $desc = '', mixed $default = null): CommandArgument
    {
        return new CommandArgument($name, $desc, required: true, default: $default);
    }

    public static function optional(string $name, string $desc = '', mixed $default = null): CommandArgument
    {
        return new CommandArgument($name, $desc, required: false, default: $default);
    }
}
