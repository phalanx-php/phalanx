<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use Phalanx\Scope\ExecutionScope;

final class StoreRegistry
{
    /** @var list<StoreRuntime> */
    private array $runtimes = [];

    /** @var array<class-string<Slice>, StoreRuntime> */
    private array $slices = [];

    private function __construct()
    {
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function fromDefinitions(StoreDefinition ...$definitions): self
    {
        $registry = new self();
        foreach ($definitions as $definition) {
            $runtime = new StoreRuntime($definition->name, $definition->strategy, $definition->slices);
            $registry->runtimes[] = $runtime;

            foreach ($definition->slices as $slice) {
                if (isset($registry->slices[$slice])) {
                    throw new StoreException("Slice {$slice} is registered by more than one store.");
                }

                $registry->slices[$slice] = $runtime;
            }
        }

        return $registry;
    }

    public function start(ExecutionScope $scope): void
    {
        foreach ($this->runtimes as $runtime) {
            $runtime->start($scope);
        }
    }

    /** @param class-string<Slice> $slice */
    public function runtime(string $slice): StoreRuntime
    {
        return $this->slices[$slice] ?? throw UnknownSlice::class($slice);
    }

    public function writer(): StoreWriter
    {
        return new StoreWriter($this);
    }

    public function lens(): Lens
    {
        return new Lens($this);
    }
}
