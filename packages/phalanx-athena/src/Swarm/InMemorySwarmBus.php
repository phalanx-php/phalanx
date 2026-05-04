<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Scope\Suspendable;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;

/**
 * In-memory implementation of the swarm bus.
 * Uses fan-out to ensure multiple subscribers receive all events.
 */
final class InMemorySwarmBus implements SwarmBus
{
    /** @var list<Channel> */
    private array $subscribers = [];

    public function emit(Suspendable $scope, SwarmEvent $event): void
    {
        foreach ($this->subscribers as $channel) {
            $channel->emit($event);
        }
    }

    public function subscribe(array $filters = []): Emitter
    {
        return Emitter::produce(function (Channel $out, StreamContext $scope) use ($filters): void {
            $subscriber = new Channel();
            $this->subscribers[] = $subscriber;
            
            $scope->onDispose(function () use ($subscriber): void {
                $idx = array_search($subscriber, $this->subscribers, true);
                if ($idx !== false) {
                    array_splice($this->subscribers, $idx, 1);
                }

                $subscriber->complete();
            });

            foreach ($subscriber->consume() as $event) {
                if ($this->matches($event, $filters)) {
                    $out->emit($event);
                }
            }
        });
    }

    /** @param array<string, mixed> $filters */
    private function matches(SwarmEvent $event, array $filters): bool
    {
        foreach ($filters as $key => $target) {
            if ($target === null) {
                continue;
            }

            if ($key === 'kinds') {
                $actual = $event->kind;
            } elseif ($key === 'addressed_to') {
                $actual = $event->addressedTo;
            } elseif ($key === 'trace_id') {
                $actual = $event->traceId;
            } elseif ($key === 'from') {
                $actual = $event->from;
            } elseif ($key === 'workspace') {
                $actual = $event->workspace;
            } elseif ($key === 'session') {
                $actual = $event->session;
            } else {
                continue;
            }

            if (!self::matchesValue($actual, $target, $key === 'addressed_to')) {
                return false;
            }
        }

        return true;
    }

    private static function matchesValue(mixed $actual, mixed $target, bool $addressed = false): bool
    {
        if ($addressed && ($target === 'ALL' || $actual === 'ALL')) {
            return true;
        }

        $actuals = array_map(self::normalizeFilterValue(...), (array) $actual);
        $targets = array_map(self::normalizeFilterValue(...), (array) $target);

        return array_intersect($targets, $actuals) !== [];
    }

    private static function normalizeFilterValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
