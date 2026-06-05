<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Reactive\Resource;
use Phalanx\Tui\Tui\Reactive\ResourceSubscription;
use Phalanx\Tui\Tui\Reactive\Signal;
use Phalanx\Tui\Tui\Reactive\SignalSubscription;
use Phalanx\Tui\Tui\Reactive\StoreSubscription;

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
