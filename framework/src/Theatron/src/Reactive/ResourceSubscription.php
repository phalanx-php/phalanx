<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactive;

final class ResourceSubscription
{
    public bool $isDisposed {
        get => $this->resource === null;
    }

    public function __construct(private ?Resource $resource, private int $subscriberId)
    {
    }

    public function dispose(): void
    {
        if ($this->resource === null) {
            return;
        }

        $resource = $this->resource;
        $this->resource = null;
        $resource->unsubscribe($this->subscriberId);
    }
}
