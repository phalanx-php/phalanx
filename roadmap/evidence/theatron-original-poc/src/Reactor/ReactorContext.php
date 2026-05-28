<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Store\StoreHandle;
use Phalanx\Theatron\Store\StoreWriter;

final class ReactorContext
{
    public function __construct(
        private(set) ExecutionScope $scope,
        private(set) Lens $lens,
        private(set) StoreWriter $writer,
        private(set) DirtyBatch $dirty,
    ) {
    }

    /**
     * @template T of \Phalanx\Theatron\Store\Slice
     * @param class-string<T> $sliceClass
     * @return StoreHandle<T>
     */
    public function store(string $sliceClass): StoreHandle
    {
        return $this->lens->handle($sliceClass);
    }
}
