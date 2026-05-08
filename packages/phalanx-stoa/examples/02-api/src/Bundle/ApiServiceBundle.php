<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Api\Bundle;

use Acme\StoaDemo\Api\Security\DemoGuard;
use Acme\StoaDemo\Api\Services\AuditLog;
use Phalanx\Auth\Guard;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class ApiServiceBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(AuditLog::class)
            ->factory(static fn(): AuditLog => new AuditLog());
        $services->singleton(DemoGuard::class)
            ->factory(static fn(): DemoGuard => new DemoGuard());
        $services->alias(Guard::class, DemoGuard::class);
    }
}
