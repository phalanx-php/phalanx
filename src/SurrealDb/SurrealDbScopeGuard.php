<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

class SurrealDbScopeGuard
{
    private bool $open = true;

    public function close(): void
    {
        $this->open = false;
    }

    public function assertOpen(): void
    {
        if (!$this->open) {
            throw new SurrealDbException('SurrealDb service was used after its owning scope was disposed.');
        }
    }
}
