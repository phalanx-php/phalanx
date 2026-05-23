<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

/**
 * Static factory for CommandArgument value objects. Use Arg::required(...)
 * for positional arguments the parser must see and Arg::optional(...) for
 * positional arguments with a default. Pairs with Opt:: for the flag side
 * of a CommandConfig declaration.
 */
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
