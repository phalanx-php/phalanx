<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Reactive;

final class RenderDependencySet
{
    public int $count {
        get => $this->countSubscriptions();
    }

    /** @var array<int, SignalSubscription|ComputedSubscription|ResourceSubscription> */
    private array $subscriptions = [];

    /** @var array<int, true> */
    private array $ignored = [];

    /** @param list<Signal|Resource> $ignored */
    public function __construct(
        private DirtyBatch $batch,
        array $ignored = [],
    ) {
        foreach ($ignored as $dep) {
            $this->ignored[spl_object_id($dep)] = true;
        }
    }

    /** @param list<object> $deps */
    public function reconcile(array $deps): void
    {
        /** @var array<int, object> $next */
        $next = [];

        foreach ($deps as $dep) {
            $id = spl_object_id($dep);
            if (isset($this->ignored[$id])) {
                continue;
            }

            if (
                $dep instanceof Signal
                || $dep instanceof Computed
                || $dep instanceof Resource
            ) {
                $next[$id] = $dep;
            }
        }

        /** @var array<int, SignalSubscription|ComputedSubscription|ResourceSubscription> $added */
        $added = [];

        try {
            foreach ($next as $id => $dep) {
                if (isset($this->subscriptions[$id])) {
                    continue;
                }

                $added[$id] = $this->subscribe($dep);
            }
        } catch (\Throwable $e) {
            foreach ($added as $subscription) {
                $subscription->dispose();
            }

            throw $e;
        }

        foreach ($this->subscriptions as $id => $subscription) {
            if (isset($next[$id])) {
                continue;
            }

            $subscription->dispose();
            unset($this->subscriptions[$id]);
        }

        $this->subscriptions += $added;
    }

    public function dispose(): void
    {
        foreach ($this->subscriptions as $subscription) {
            $subscription->dispose();
        }

        $this->subscriptions = [];
    }

    private function subscribe(object $dep): SignalSubscription|ComputedSubscription|ResourceSubscription
    {
        $batch = $this->batch;
        $subscriber = static function () use ($batch): void {
            $batch->request();
        };

        if ($dep instanceof Signal) {
            return $dep->subscribe($subscriber);
        }

        if ($dep instanceof Computed) {
            return $dep->subscribe($subscriber);
        }

        /** @var Resource $dep */
        return $dep->subscribe($subscriber);
    }

    private function countSubscriptions(): int
    {
        return count($this->subscriptions);
    }
}
