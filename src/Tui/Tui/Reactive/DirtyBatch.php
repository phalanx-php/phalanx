<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Reactive;

final class DirtyBatch
{
    private(set) int $requests = 0;
    private(set) bool $isDirty = false;

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
