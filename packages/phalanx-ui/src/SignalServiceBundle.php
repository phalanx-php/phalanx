<?php

declare(strict_types=1);

namespace Phalanx\Ui;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Ui\Signal\SignalCollector;

final class SignalServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->scoped(SignalCollector::class)
            ->factory(static fn(): SignalCollector => new SignalCollector());
    }
}
