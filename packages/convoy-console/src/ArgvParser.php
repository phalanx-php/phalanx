<?php

declare(strict_types=1);

namespace Convoy\Console;

final class ArgvParser
{
    /**
     * @param list<string> $argv Raw arguments (after command name is stripped)
     */
    public static function parse(array $argv, CommandConfig $config): CommandInput
    {
        $optionsByName = self::indexOptions($config);
        $shorthandMap = self::buildShorthandMap($config);

        $parsedOptions = [];
        $positional = [];
        $optionsParsing = true;

        for ($i = 0, $count = count($argv); $i < $count; $i++) {
            $token = $argv[$i];

            if ($token === '--') {
                $optionsParsing = false;
                continue;
            }

            if ($optionsParsing && str_starts_with($token, '--')) {
                $i = self::parseLongOption($token, $argv, $i, $optionsByName, $parsedOptions, $config);
                continue;
            }

            if ($optionsParsing && str_starts_with($token, '-') && strlen($token) > 1) {
                $i = self::parseShortOption($token, $argv, $i, $shorthandMap, $optionsByName, $parsedOptions, $config);
                continue;
            }

            $positional[] = $token;
        }

        self::applyOptionDefaults($config, $parsedOptions);
        $args = self::matchPositionalArgs($positional, $config);

        return new CommandInput(
            new CommandArgs($args),
            new CommandOptions($parsedOptions),
        );
    }

    /**
     * @param array<string, CommandOption> $optionsByName
     * @param array<string, mixed> $parsedOptions
     * @return int Updated index
     */
    private static function parseLongOption(
        string $token,
        array $argv,
        int $i,
        array $optionsByName,
        array &$parsedOptions,
        CommandConfig $config,
    ): int {
        $rest = substr($token, 2);

        if (str_contains($rest, '=')) {
            [$name, $value] = explode('=', $rest, 2);

            if (!isset($optionsByName[$name])) {
                throw new InvalidInputException("Unknown option: --$name", $config);
            }

            $parsedOptions[$name] = $value;

            return $i;
        }

        $name = $rest;

        if (!isset($optionsByName[$name])) {
            throw new InvalidInputException("Unknown option: --$name", $config);
        }

        $option = $optionsByName[$name];

        if ($option->requiresValue) {
            $next = $i + 1;

            if ($next >= count($argv)) {
                throw new InvalidInputException("Option --$name requires a value", $config);
            }

            $parsedOptions[$name] = $argv[$next];

            return $next;
        }

        $parsedOptions[$name] = true;

        return $i;
    }

    /**
     * @param array<string, string> $shorthandMap
     * @param array<string, CommandOption> $optionsByName
     * @param array<string, mixed> $parsedOptions
     * @return int Updated index
     */
    private static function parseShortOption(
        string $token,
        array $argv,
        int $i,
        array $shorthandMap,
        array $optionsByName,
        array &$parsedOptions,
        CommandConfig $config,
    ): int {
        $short = substr($token, 1);

        if (!isset($shorthandMap[$short])) {
            throw new InvalidInputException("Unknown option: -$short", $config);
        }

        $name = $shorthandMap[$short];
        $option = $optionsByName[$name];

        if ($option->requiresValue) {
            $next = $i + 1;

            if ($next >= count($argv)) {
                throw new InvalidInputException("Option -$short (--$name) requires a value", $config);
            }

            $parsedOptions[$name] = $argv[$next];

            return $next;
        }

        $parsedOptions[$name] = true;

        return $i;
    }

    /**
     * @param array<string, mixed> $parsedOptions
     */
    private static function applyOptionDefaults(CommandConfig $config, array &$parsedOptions): void
    {
        foreach ($config->options as $option) {
            if (!array_key_exists($option->name, $parsedOptions) && $option->default !== null) {
                $parsedOptions[$option->name] = $option->default;
            }
        }
    }

    /**
     * @param list<string> $positional
     * @return array<string, mixed>
     */
    private static function matchPositionalArgs(array $positional, CommandConfig $config): array
    {
        $args = [];

        foreach ($config->arguments as $index => $argument) {
            if (isset($positional[$index])) {
                $args[$argument->name] = $positional[$index];
            } elseif ($argument->required) {
                throw new InvalidInputException("Missing required argument: {$argument->name}", $config);
            } elseif ($argument->default !== null) {
                $args[$argument->name] = $argument->default;
            }
        }

        return $args;
    }

    /** @return array<string, CommandOption> */
    private static function indexOptions(CommandConfig $config): array
    {
        $map = [];

        foreach ($config->options as $option) {
            $map[$option->name] = $option;
        }

        return $map;
    }

    /** @return array<string, string> shorthand => option name */
    private static function buildShorthandMap(CommandConfig $config): array
    {
        $map = [];

        foreach ($config->options as $option) {
            if ($option->shorthand !== '') {
                $map[$option->shorthand] = $option->name;
            }
        }

        return $map;
    }
}
