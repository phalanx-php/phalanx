<?php

declare(strict_types=1);

namespace Phalanx\Console;

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

                if ($option->default !== null) {
                    $desc .= " (default: {$option->default})";
                }

                $lines[] = "{$labels[$i]}$padding$desc";
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

            $names = array_keys($commands);
            $maxLen = $names !== [] ? max(array_map(strlen(...), $names)) : 0;

            foreach ($commands as $cmdName => $handler) {
                $desc = $handler->config instanceof CommandConfig ? $handler->config->description : '';
                $padding = str_repeat(' ', $maxLen - strlen($cmdName) + 2);
                $lines[] = "  {$cmdName}{$padding}{$desc}";
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
