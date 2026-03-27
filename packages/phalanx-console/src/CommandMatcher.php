<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\ExecutionScope;
use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerMatcher;
use Phalanx\Handler\MatchResult;
use RuntimeException;

final class CommandMatcher implements HandlerMatcher
{
    /** @param array<string, Handler> $handlers */
    public function match(ExecutionScope $scope, array $handlers): ?MatchResult
    {
        $name = $scope->attribute('command');

        if ($name === null) {
            return null;
        }

        $handler = $handlers[$name] ?? null;

        if ($handler === null) {
            throw new RuntimeException("Command not found: $name");
        }

        /** @var list<string> $rawArgs */
        $rawArgs = $scope->attribute('args', []);
        $config = $handler->config instanceof CommandConfig ? $handler->config : new CommandConfig();

        $input = ArgvParser::parse($rawArgs, $config);

        foreach ($config->validators as $validator) {
            $validator->validate($input, $config);
        }

        $scope = new ExecutionContext(
            $scope,
            $name,
            $input->args,
            $input->options,
            $config,
        );

        return new MatchResult($handler, $scope);
    }
}
