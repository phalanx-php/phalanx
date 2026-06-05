<?php

declare(strict_types=1);

namespace Phalanx\Console\Command;

/**
 * Pure string formatter for `--help` / `help` output. Renders a single
 * command (forCommand), a subcommand group (forGroup), or the top-level
 * application (forTopLevel) from the declarative CommandConfig and
 * CommandGroup metadata. No I/O, no scope, no service dependencies.
 */
final class HelpGenerator
{
    public static function forCommand(string $name, CommandConfig $config): string
    {
        $lines = [];

        if ($config->description !== '') {
            $lines[] = $config->description;
            $lines[] = '';
        }

        $lines[] = 'Usage:';
        $usage = "  $name";

        foreach ($config->arguments as $arg) {
            $usage .= $arg->required ? " <{$arg->name}>" : " [{$arg->name}]";
        }

        if ($config->options !== []) {
            $usage .= ' [options]';
        }

        $lines[] = $usage;

        if ($config->arguments !== []) {
            $lines[] = '';
            $lines[] = 'Arguments:';

            $maxLen = max(array_map(static fn(CommandArgument $a) => strlen($a->name), $config->arguments));

            foreach ($config->arguments as $arg) {
                $padding = str_repeat(' ', $maxLen - strlen($arg->name) + 2);
                $desc = $arg->description;

                if (!$arg->required && $arg->default !== null) {
                    $desc .= " (default: {$arg->default})";
                }

                $lines[] = "  {$arg->name}$padding$desc";
            }
        }

        if ($config->options !== []) {
            $lines[] = '';
            $lines[] = 'Options:';

            $labels = [];

            foreach ($config->options as $option) {
                $label = "  --{$option->name}";

                if ($option->shorthand !== '') {
                    $label = "  -{$option->shorthand}, --{$option->name}";
                }

                if ($option->requiresValue) {
                    $label .= '=<value>';
                }

                $labels[] = $label;
            }

            $maxLen = max(array_map(strlen(...), $labels));

            foreach ($config->options as $i => $option) {
                $padding = str_repeat(' ', $maxLen - strlen($labels[$i]) + 2);
                $desc = $option->description;

                if ($option->default !== null && $option->default !== false && $option->default !== '') {
                    $desc .= " (default: {$option->default})";
                }

                $lines[] = "{$labels[$i]}$padding$desc";
            }
        }

        if ($config->aliases !== []) {
            $lines[] = '';
            $lines[] = 'Aliases: ' . implode(', ', $config->aliases);
        }

        if ($config->examples !== []) {
            $lines[] = '';
            $lines[] = 'Examples:';

            foreach ($config->examples as $example) {
                $lines[] = "  {$example}";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public static function forGroup(string $name, CommandGroup $group): string
    {
        $lines = [];

        if ($group->description() !== '') {
            $lines[] = $group->description();
            $lines[] = '';
        }

        $lines[] = 'Usage:';
        $lines[] = "  $name <command> [options]";
        $lines[] = '';

        $commands = $group->commands();
        $subgroups = $group->groups();

        if ($commands !== []) {
            $lines[] = 'Commands:';

            $seen = [];
            $entries = [];

            foreach ($commands as $cmdName => $handler) {
                $aliasOf = null;

                foreach ($entries as $entryName => $entryHandler) {
                    if ($entryHandler->task !== $handler->task) {
                        continue;
                    }

                    $entryAliases = $entryHandler->config instanceof CommandConfig ? $entryHandler->config->aliases : [];
                    $handlerAliases = $handler->config instanceof CommandConfig ? $handler->config->aliases : [];

                    if (in_array($cmdName, $entryAliases, strict: true) || in_array($entryName, $handlerAliases, strict: true)) {
                        $aliasOf = $entryName;
                        break;
                    }
                }

                if ($aliasOf !== null) {
                    $seen[$aliasOf][] = $cmdName;
                    continue;
                }

                $seen[$cmdName] = [$cmdName];
                $entries[$cmdName] = $handler;
            }

            $names = array_keys($entries);
            $maxLen = $names !== [] ? max(array_map(strlen(...), $names)) : 0;

            foreach ($entries as $cmdName => $handler) {
                $desc = $handler->config instanceof CommandConfig ? $handler->config->description : '';
                $aliases = $seen[$cmdName];
                $aliasStr = count($aliases) > 1 ? ' (' . implode(', ', array_slice($aliases, 1)) . ')' : '';
                $padding = str_repeat(' ', $maxLen - strlen($cmdName) + 2);
                $lines[] = "  {$cmdName}{$padding}{$desc}{$aliasStr}";
            }
        }

        if ($subgroups !== []) {
            if ($commands !== []) {
                $lines[] = '';
            }
            $lines[] = 'Groups:';

            $names = array_keys($subgroups);
            $maxLen = $names !== [] ? max(array_map(strlen(...), $names)) : 0;

            foreach ($subgroups as $groupName => $subgroup) {
                $desc = $subgroup->description();
                $padding = str_repeat(' ', $maxLen - strlen($groupName) + 2);
                $lines[] = "  {$groupName}{$padding}{$desc}";
            }
        }

        $lines[] = '';
        $lines[] = "Run '$name <command> --help' for details.";

        return implode("\n", $lines) . "\n";
    }

    public static function forTopLevel(CommandGroup $group): string
    {
        return self::forGroup($group->description() ?: 'app', $group);
    }
}
