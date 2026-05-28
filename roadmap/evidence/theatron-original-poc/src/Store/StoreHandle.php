<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use Closure;

/**
 * @template T of Slice
 */
final class StoreHandle
{
    public Slice $value {
        get => $this->runtime->read($this->slice);
        set(Slice $value) {
            if (!$value instanceof $this->slice) {
                throw new StoreException(sprintf('Expected %s, got %s.', $this->slice, $value::class));
            }

            $this->writer->set($value);
        }
    }

    /**
     * @param class-string<T> $slice
     */
    public function __construct(
        private readonly string $slice,
        private readonly StoreRuntime $runtime,
        private readonly StoreWriter $writer,
    ) {
    }

    /**
     * @param Closure(T): T $update
     * @return T
     */
    public function update(Closure $update): Slice
    {
        return $this->writer->update($this->slice, $update);
    }

    public function subscribe(Closure $subscriber): StoreSubscription
    {
        return $this->runtime->subscribe($this->slice, $subscriber);
    }
}
