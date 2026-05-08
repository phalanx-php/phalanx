<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime;

use Acme\StoaDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class RuntimeLifecycleBundle extends ServiceBundle
{
    /** @param array<string, mixed> $context */
    public function services(Services $services, array $context): void
    {
        $services->singleton(RuntimeEvents::class)
            ->factory(static fn(): RuntimeEvents => new RuntimeEvents());
    }
}
