<?php

declare(strict_types=1);

namespace Phalanx\Athena\Pipeline;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;

final class Pipeline implements Executable
{
    /** @var list<array{type: string, value: mixed}> */
    private array $steps = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public function step(Scopeable|Executable $task): self
    {
        $clone = clone $this;
        $clone->steps[] = ['type' => 'step', 'value' => $task];
        return $clone;
    }

    /** @param callable(mixed): (Scopeable|Executable) $fn */
    public function branch(callable $fn): self
    {
        $clone = clone $this;
        $clone->steps[] = ['type' => 'branch', 'value' => $fn];
        return $clone;
    }

    /** @param list<Scopeable|Executable> $tasks */
    public function fan(array $tasks): self
    {
        $clone = clone $this;
        $clone->steps[] = ['type' => 'fan', 'value' => $tasks];
        return $clone;
    }

    public function run(mixed $input = null): self
    {
        if ($input !== null) {
            $clone = clone $this;
            array_unshift($clone->steps, ['type' => 'input', 'value' => $input]);
            return $clone;
        }

        return $this;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $result = null;

        foreach ($this->steps as $step) {
            $result = match ($step['type']) {
                'input' => $step['value'],
                'step' => $scope->execute($step['value']),
                'branch' => $scope->execute(($step['value'])($result)),
                'fan' => $scope->concurrent(
                    array_map(
                        static fn($task) => Task::of(static fn($s) => $s->execute($task)),
                        $step['value'],
                    ),
                ),
                default => $result,
            };
        }

        return $result;
    }
}
