<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Scope\Scope;
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

    public function emit(Scope&Suspendable $scope, SwarmEvent $event): void
    {
        foreach ($this->subscribers as $channel) {
            $channel->emit($event);
        }
    }

    public function subscribe(array $filters = []): Emitter
    {
        $bus = $this;

        return Emitter::produce(static function (Channel $out, StreamContext $scope) use ($bus, $filters): void {
            $subscriber = new Channel();
            $bus->subscribers[] = $subscriber;

            $scope->onDispose(static function () use ($bus, $subscriber): void {
                $idx = array_search($subscriber, $bus->subscribers, true);
                if ($idx !== false) {
                    array_splice($bus->subscribers, $idx, 1);
                }

                $subscriber->complete();
            });

            foreach ($subscriber->consume() as $event) {
                if ($bus->matches($event, $filters)) {
                    $out->emit($event);
                }
            }
        });
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
}
