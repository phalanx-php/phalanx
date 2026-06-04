<?php

declare(strict_types=1);

namespace Phalanx\Scheduling;

use Closure;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Recovery\RecoveryPreset;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class ScheduleBuilder
{
    private string $mode = 'task';

    /** @var list<Scopeable|Executable|Closure> */
    private array $tasks = [];

    private ?RecoveryPlan $recovery = null;

    private TaskPriority $priority = TaskPriority::Normal;

    private ?int $maxConcurrency = null;

    private ?LockKey $exclusive = null;

    private ?SchedulePolicy $policy = null;

    public function __construct(
        private ExecutionScope $scope,
    ) {
    }

    public function task(Scopeable|Executable|Closure $task): self
    {
        $clone = clone $this;
        $clone->mode = 'task';
        $clone->tasks = [$task];

        return $clone;
    }

    public function concurrent(Scopeable|Executable|Closure ...$tasks): self
    {
        $clone = clone $this;
        $clone->mode = 'concurrent';
        $clone->tasks = array_values($tasks);

        return $clone;
    }

    public function race(Scopeable|Executable|Closure ...$tasks): self
    {
        $clone = clone $this;
        $clone->mode = 'race';
        $clone->tasks = array_values($tasks);

        return $clone;
    }

    public function any(Scopeable|Executable|Closure ...$tasks): self
    {
        $clone = clone $this;
        $clone->mode = 'any';
        $clone->tasks = array_values($tasks);

        return $clone;
    }

    public function series(Scopeable|Executable|Closure ...$tasks): self
    {
        $clone = clone $this;
        $clone->mode = 'series';
        $clone->tasks = array_values($tasks);

        return $clone;
    }

    public function waterfall(Scopeable|Executable|Closure ...$tasks): self
    {
        $clone = clone $this;
        $clone->mode = 'waterfall';
        $clone->tasks = array_values($tasks);

        return $clone;
    }

    public function settle(Scopeable|Executable|Closure ...$tasks): self
    {
        $clone = clone $this;
        $clone->mode = 'settle';
        $clone->tasks = array_values($tasks);

        return $clone;
    }

    public function recovery(RecoveryPlan|RecoveryPreset $recovery): self
    {
        $clone = clone $this;
        $clone->recovery = $recovery instanceof RecoveryPreset ? $recovery->toPlan() : $recovery;

        return $clone;
    }

    public function priority(TaskPriority $priority): self
    {
        $clone = clone $this;
        $clone->priority = $priority;

        return $clone;
    }

    public function maxConcurrency(int $limit): self
    {
        $clone = clone $this;
        $clone->maxConcurrency = $limit;

        return $clone;
    }

    public function exclusive(LockKey $key): self
    {
        $clone = clone $this;
        $clone->exclusive = $key;

        return $clone;
    }

    public function policy(SchedulePolicy $policy): self
    {
        $clone = clone $this;
        $clone->policy = $policy;

        return $clone;
    }

    public function result(): mixed
    {
        $plan = $this->freeze();

        return match ($plan->mode) {
            'task' => $this->scope->execute($plan->tasks[0]),
            'concurrent' => $this->scope->concurrent(...$plan->tasks),
            'race' => $this->scope->race(...$plan->tasks),
            'any' => $this->scope->any(...$plan->tasks),
            'series' => $this->scope->series(...$plan->tasks),
            'waterfall' => $this->scope->waterfall(...$plan->tasks),
            'settle' => $this->scope->settle(...$plan->tasks),
            default => throw new \LogicException("Unknown schedule mode: {$plan->mode}"),
        };
    }

    public function freeze(): SchedulePlan
    {
        $builder = $this->policy !== null ? $this->policy->configure($this) : $this;

        return new SchedulePlan(
            mode: $builder->mode,
            tasks: $builder->tasks,
            recovery: $builder->recovery,
            priority: $builder->priority,
            maxConcurrency: $builder->maxConcurrency,
            exclusive: $builder->exclusive,
            policy: $builder->policy,
        );
    }
}
