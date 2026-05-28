<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Reactor;

use Phalanx\Theatron\Demos\Showcase\Event\AgentStatusEvent;
use Phalanx\Theatron\Demos\Showcase\Event\AgentTokenEvent;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentMetricsSlice;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentRosterSlice;
use Phalanx\Theatron\Demos\Showcase\Slice\DispatchFeedSlice;
use Phalanx\Theatron\Reactor\ReactorContext;
use Phalanx\Theatron\Reactor\StreamReactor;
use Phalanx\Theatron\Stream\TheatronStream;

final class AgentEventReactor implements StreamReactor
{
    public function subscribe(TheatronStream $stream, ReactorContext $context): void
    {
        $writer = $context->writer;

        $stream->subscribe(AgentTokenEvent::class, static function (AgentTokenEvent $event) use ($writer): void {
            $writer->update(
                DispatchFeedSlice::class,
                static fn(DispatchFeedSlice $feed): DispatchFeedSlice => $feed->appendToken($event->agentId, $event->delta),
            );

            $writer->update(
                AgentRosterSlice::class,
                static fn(AgentRosterSlice $roster): AgentRosterSlice => $roster->withTokens($event->agentId, 1),
            );

            $writer->update(
                AgentMetricsSlice::class,
                static fn(AgentMetricsSlice $m): AgentMetricsSlice => new AgentMetricsSlice(
                    totalTokens: $m->totalTokens + 1,
                    activeWorkers: $m->activeWorkers,
                    completedAgents: $m->completedAgents,
                    tokensPerSecond: $m->tokensPerSecond,
                ),
            );
        });

        $stream->subscribe(AgentStatusEvent::class, static function (AgentStatusEvent $event) use ($writer): void {
            $writer->update(
                AgentRosterSlice::class,
                static fn(AgentRosterSlice $roster): AgentRosterSlice => $roster->withStatus($event->agentId, $event->status),
            );

            if ($event->status === 'thinking') {
                $writer->update(
                    AgentMetricsSlice::class,
                    static fn(AgentMetricsSlice $m): AgentMetricsSlice => new AgentMetricsSlice(
                        totalTokens: $m->totalTokens,
                        activeWorkers: $m->activeWorkers + 1,
                        completedAgents: $m->completedAgents,
                        tokensPerSecond: $m->tokensPerSecond,
                    ),
                );
            }

            if ($event->status === 'complete' || $event->status === 'error') {
                $writer->update(
                    DispatchFeedSlice::class,
                    static fn(DispatchFeedSlice $feed): DispatchFeedSlice => $feed->finalizeStream($event->agentId),
                );

                $writer->update(
                    AgentMetricsSlice::class,
                    static fn(AgentMetricsSlice $m): AgentMetricsSlice => new AgentMetricsSlice(
                        totalTokens: $event->totalTokens > 0 ? $event->totalTokens : $m->totalTokens,
                        activeWorkers: max(0, $m->activeWorkers - 1),
                        completedAgents: $m->completedAgents + ($event->status === 'complete' ? 1 : 0),
                        tokensPerSecond: $m->tokensPerSecond,
                    ),
                );
            }
        });
    }
}
