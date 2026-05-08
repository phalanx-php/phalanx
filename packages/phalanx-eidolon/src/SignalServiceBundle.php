<?php

declare(strict_types=1);

namespace Phalanx\Eidolon;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Eidolon\Signal\SignalCollector;

final class SignalServiceBundle extends ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->scoped(SignalCollector::class)
            ->factory(static fn(): SignalCollector => new SignalCollector());
    }
}
