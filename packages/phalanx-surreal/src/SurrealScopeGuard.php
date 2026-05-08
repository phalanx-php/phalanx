<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

class SurrealScopeGuard
{
    private bool $open = true;

    public function close(): void
    {
        $this->open = false;
    }

    public function assertOpen(): void
    {
        if (!$this->open) {
            throw new SurrealException('Surreal service was used after its owning scope was disposed.');
        }
    }
}
