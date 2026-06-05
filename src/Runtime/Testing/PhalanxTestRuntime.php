<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use ReflectionFunction;
use RuntimeException;

class PhalanxTestRuntime
{
    public RuntimeMemory $memory {
        get => $this->app->runtime()->memory;
    }

    public PhalanxTestScope $scope {
        get => $this->scopeFixture ??= new PhalanxTestScope($this);
    }

    private ?PhalanxTestScope $scopeFixture = null;

    private bool $shutdown = false;

    public function __construct(
        public readonly Application $app,
    ) {
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function start(
        ?Closure $services = null,
        array $context = [],
    ): self {
        $builder = Application::starting($context);

        if ($services !== null) {
            $builder = $builder->providers(new InlineServiceBundle($services));
        }

        return new self($builder->compile());
    }

    /**
     * @template T
     * @param Closure(ExecutionScope): T $test
     * @return T
     */
    public function run(
        Closure $test,
        string $name,
        ?CancellationToken $token = null,
    ): mixed {
        $reflection = new ReflectionFunction($test);
        if (!$reflection->isStatic()) {
            throw new RuntimeException('Phalanx test scope bodies must be static closures.');
        }

        return $this->app->scoped(
            Task::named(
                $name,
                static fn(ExecutionScope $scope): mixed => $test($scope),
            ),
            $token,
        );
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }
        $this->shutdown = true;
        $this->app->shutdown();
    }
}
