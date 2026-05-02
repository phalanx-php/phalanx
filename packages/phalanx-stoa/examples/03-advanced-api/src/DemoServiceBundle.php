<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced;

use Acme\StoaDemo\Advanced\Security\DemoGuard;
use Acme\StoaDemo\Advanced\Services\AuditLog;
use Phalanx\Auth\Guard;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class DemoServiceBundle implements ServiceBundle
{
    /** @param array<string, mixed> $context */
    public function services(Services $services, array $context): void
    {
        $services->singleton(AuditLog::class)
            ->factory(static fn(): AuditLog => new AuditLog());
        $services->singleton(DemoGuard::class)
            ->factory(static fn(): DemoGuard => new DemoGuard());
        $services->alias(Guard::class, DemoGuard::class);
    }
}
