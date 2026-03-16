<?php

declare(strict_types=1);

namespace Convoy\Task;

use Closure;
use Convoy\Scope;
use InvalidArgumentException;
use ReflectionFunction;

final readonly class Task implements Scopeable
{
    private function __construct(
        private Closure $work,
        public TaskConfig $config = new TaskConfig(),
    ) {
        $rf = new ReflectionFunction($work);
        if ($rf->getClosureThis() !== null) {
            throw new InvalidArgumentException(
                'Task closure must be static to prevent reference cycles. ' .
                'Use: static fn(ExecutionScope $es) => ... or pass $this via use()'
            );
        }
    }

    public static function of(Closure $work): self
    {
        return new self($work);
    }

    public static function create(Closure $work, TaskConfig $config): self
    {
        return new self($work, $config);
    }

    public function with(TaskConfig $config): self
    {
        return new self($this->work, $config);
    }

    public function withConfig(
        ?string $name = null,
        ?int $priority = null,
        mixed $pool = null,
        mixed $retry = null,
        ?float $timeout = null,
    ): self {
        return new self($this->work, $this->config->with(
            name: $name,
            priority: $priority,
            pool: $pool,
            retry: $retry,
            timeout: $timeout,
        ));
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->work)($scope);
    }
}
