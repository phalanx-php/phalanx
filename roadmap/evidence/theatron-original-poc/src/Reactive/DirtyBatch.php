<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactive;

final class DirtyBatch
{
    private(set) bool $isDirty = false;
    private(set) int $requests = 0;

    public function request(): void
    {
        if ($this->isDirty) {
            return;
        }

        $this->isDirty = true;
        $this->requests++;
    }

    public function consume(): bool
    {
        if (!$this->isDirty) {
            return false;
        }

        $this->isDirty = false;

        return true;
    }
}
