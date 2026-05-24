<?php

declare(strict_types=1);

namespace Phalanx\Eidolon;

use Phalanx\Boot\AppContext;
use Phalanx\Eidolon\Signal\SignalCollector;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class SignalServiceBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->scoped(SignalCollector::class)
            ->factory(static fn(): SignalCollector => new SignalCollector());
    }
}
