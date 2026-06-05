<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Api\Routes;

use Acme\HttpDemo\Api\Services\AuditLog;
use Phalanx\Http\AuthRequestContext;
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
