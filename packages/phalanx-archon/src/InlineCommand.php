<?php

declare(strict_types=1);

namespace Phalanx\Archon;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Traceable;
use ReflectionFunction;
use RuntimeException;

/** @internal */
final class InlineCommand implements Executable, Traceable
{
    public string $traceName {
        get => "archon.command.{$this->name}";
    }

    public CommandConfig $config {
        get => $this->commandConfig;
    }

    private function __construct(
        private readonly string $name,
        private readonly Closure|Scopeable|Executable $handler,
        private readonly CommandConfig $commandConfig,
    ) {
    }

    public static function named(
        string $name,
        Closure|Scopeable|Executable $handler,
        ?CommandConfig $config = null,
    ): self {
        if ($handler instanceof Closure) {
            self::assertStaticClosure($handler);
        }

        return new self($name, $handler, $config ?? new CommandConfig());
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $context = ExecutionContext::fromScope($scope, $this->name, $this->commandConfig);

        return ($this->handler)($context);
    }

    private static function assertStaticClosure(Closure $handler): void
    {
        if (new ReflectionFunction($handler)->isStatic()) {
            return;
        }

        throw new RuntimeException(
            'Archon inline commands require static closures. Non-static closures capture $this '
            . 'and leak in long-running console runtimes.',
        );
    }
}
