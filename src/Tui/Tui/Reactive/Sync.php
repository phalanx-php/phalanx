<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Reactive;

use Closure;
use Phalanx\Scope\TaskScope;
use ReflectionFunction;
use RuntimeException;

final class Sync
{
    private bool $disposed = false;
    private ?Closure $cleanup = null;

    public function __construct(
        private Closure $setup,
        private ?TaskScope $scope = null,
        private mixed $currentKey = null,
    ) {
        if (!new ReflectionFunction($setup)->isStatic()) {
            throw new RuntimeException('Sync setup must be a static closure.');
        }

        $this->run();
    }

    public function update(mixed $key): void
    {
        if ($this->disposed || $key === $this->currentKey) {
            return;
        }

        $this->currentKey = $key;
        $this->runCleanup();
        $this->run();
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        $this->runCleanup();
    }

    private function run(): void
    {
        $this->cleanup = ($this->setup)($this->scope);
    }

    private function runCleanup(): void
    {
        if ($this->cleanup === null) {
            return;
        }

        $cleanup = $this->cleanup;
        $this->cleanup = null;
        $cleanup();
    }
}
