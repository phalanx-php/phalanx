<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

/**
 * Static factory for CommandOption value objects. Opt::flag(...) declares
 * a boolean toggle (`--shout`, `-s`); Opt::value(...) declares an option
 * that requires a string payload (`--name=ada`). Pairs with Arg:: for
 * positional arguments in a CommandConfig declaration.
 */
final class Opt
{
    public static function flag(string $name, string $shorthand = '', string $desc = ''): CommandOption
    {
        return new CommandOption($name, $shorthand, $desc, requiresValue: false, default: false);
    }

    public static function value(
        string $name,
        string $shorthand = '',
        string $desc = '',
        mixed $default = null,
    ): CommandOption {
        return new CommandOption($name, $shorthand, $desc, requiresValue: true, default: $default);
    }
}
