<?php

declare(strict_types=1);

namespace Phalanx\Engine;

/** Kernel-internal cooperative cancellation cell; checks walk up the chain. */
final class CancelFlag
{
    private bool $raised = false;

    public function __construct(
        private readonly ?self $parent = null,
    ) {
    }

    public function raise(): void
    {
        $this->raised = true;
    }

    public function isRaised(): bool
    {
        return $this->raised || ($this->parent?->isRaised() ?? false);
    }
}
