<?php

declare(strict_types=1);

namespace Convoy\Service;

use Closure;
use Convoy\Lifecycle\LifecycleCallbacks;
use Convoy\Support\ClassNames;

final readonly class CompiledService
{
    /**
     * @param class-string $type
     */
    public function __construct(
        public string $type,
        /** @var list<string> */
        public array $dependencyOrder,
        public Closure $factory,
        public bool $singleton,
        public bool $lazy,
        public LifecycleCallbacks $lifecycle,
    ) {
    }

    public function shortName(): string
    {
        return ClassNames::short($this->type);
    }
}
