<?php

declare(strict_types=1);

namespace Phalanx\Archon\Demo;

use Phalanx\Archon\CommandScope;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;

final class DemoCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $name = (string) $scope->args->get('name', 'operator');
        $label = (string) $scope->execute(Task::named(
            'archon.demo.label',
            static fn(ExecutionScope $taskScope): string => 'scope:' . $taskScope->attribute('command'),
        ));

        $message = "Hello, {$name}.";
        if ($scope->options->flag('shout')) {
            $message = mb_strtoupper($message);
        }

        $scope->service(StreamOutput::class)->persist(
            $message,
            "command={$scope->commandName}",
            "resource={$scope->commandResourceId}",
            "task={$label}",
        );

        return 0;
    }
}
