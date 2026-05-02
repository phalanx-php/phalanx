<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced\Routes;

use Acme\StoaDemo\Advanced\Services\AuditLog;
use Phalanx\Stoa\AuthRequestScope;
use Phalanx\Task\Scopeable;

final class WhoAmI implements Scopeable
{
    public function __construct(private readonly AuditLog $audit) {}

    /** @return array{identity: string|int|null, audit_events: int} */
    public function __invoke(AuthRequestScope $scope): array
    {
        return [
            'identity' => $scope->auth->identity?->id,
            'audit_events' => $this->audit->count,
        ];
    }
}
