<?php

declare(strict_types=1);

namespace Convoy\Console;

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
}
