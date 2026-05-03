<?php

declare(strict_types=1);

namespace Phalanx\Archon;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerMatcher;
use Phalanx\Handler\MatchResult;
use Phalanx\Scope\ExecutionScope;

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
            throw UnknownCommand::named($name);
        }

        $config = $handler->config instanceof CommandConfig ? $handler->config : new CommandConfig();

        return new MatchResult($handler, ExecutionContext::fromScope($scope, $name, $config));
    }
}
