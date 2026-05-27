<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Api\Routes;

use Acme\StoaDemo\Api\Services\AuditLog;
use Phalanx\Stoa\AuthRequestContext;
use Phalanx\Task\Scopeable;

final class WhoAmI implements Scopeable
{
    public function __construct(private readonly AuditLog $audit)
    {
    }

    /** @return array{identity: string|int|null, audit_events: int} */
    public function __invoke(AuthRequestContext $ctx): array
    {
        return [
            'identity' => $ctx->auth->identity?->id,
            'audit_events' => $this->audit->count,
        ];
    }
}
