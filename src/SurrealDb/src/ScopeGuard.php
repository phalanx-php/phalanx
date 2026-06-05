<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

class ScopeGuard
{
    private bool $open = true;

    public function close(): void
    {
        $this->open = false;
    }

    public function assertOpen(): void
    {
        if (!$this->open) {
            throw new \Phalanx\SurrealDb\Exception('SurrealDb service was used after its owning scope was disposed.');
        }
    }
}
