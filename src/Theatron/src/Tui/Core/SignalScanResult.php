<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Reactive\Resource;
use Phalanx\Theatron\Tui\Reactive\ResourceSubscription;
use Phalanx\Theatron\Tui\Reactive\Signal;
use Phalanx\Theatron\Tui\Reactive\SignalSubscription;
use Phalanx\Theatron\Tui\Reactive\StoreSubscription;

final class SignalScanResult
{
    /**
     * @param list<Signal> $ownedSignals
     * @param list<SignalSubscription|ResourceSubscription> $subscriptions
     * @param list<StoreSubscription> $storeSubscriptions
     * @param list<Signal|Resource> $renderIgnoredReactives
     */
    public function __construct(
        private(set) array $ownedSignals,
        private(set) array $subscriptions,
        private(set) array $storeSubscriptions = [],
        private(set) array $renderIgnoredReactives = [],
    ) {
    }
}
