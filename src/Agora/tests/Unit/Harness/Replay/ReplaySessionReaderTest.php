<?php

declare(strict_types=1);

namespace Phalanx\Agora\Tests\Unit\Harness\Replay;

use DateTimeImmutable;
use Phalanx\Agora\Harness\EventReader;
use Phalanx\Agora\Harness\EventSource;
use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\MemoryEventLog;
use Phalanx\Agora\Harness\ProjectionSet;
use Phalanx\Agora\Harness\Replay\ProjectionCheckpointReader;
use Phalanx\Agora\Harness\Replay\ReplaySessionReader;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReplaySessionReaderTest extends TestCase
{
    private const string SESSION_ID = 'session.replay';
    private const string TURN_ID = 'turn.replay';

    #[Test]
    public function itReplaysTailEventsAfterLatestCheckpointAndKeepsFullOrderedEvents(): void
    {
        $events = [
            self::userEvent(1),
            self::token(2, 'thinking ', Channel::Thinking),
            self::token(3, 'final ', Channel::Message),
            self::token(4, 'answer', Channel::Message),
        ];
        $log = new SpyEventReader($events);
        $checkpoint = ProjectionSet::empty(self::SESSION_ID)
            ->apply($events[0])
            ->apply($events[1]);

        $session = (new ReplaySessionReader($log, new StaticCheckpointReader($checkpoint)))
            ->read(self::SESSION_ID);
        $fullReplay = self::project($events);

        self::assertSame(2, $session->checkpointSequence);
        self::assertSame($fullReplay->conversation->state(), $session->projections->conversation->state());
        self::assertSame($events, $session->events);
        self::assertSame([0], $log->requestedSequences);
    }

    #[Test]
    public function itStartsFromEmptyProjectionsWhenNoCheckpointExists(): void
    {
        $events = [
            self::userEvent(1),
            self::token(2, 'hello', Channel::Message),
        ];

        $session = (new ReplaySessionReader(self::log($events), new StaticCheckpointReader(null)))
            ->read(self::SESSION_ID);

        self::assertSame(0, $session->checkpointSequence);
        self::assertSame(2, $session->projections->eventSequence());
        self::assertSame('hello', $session->projections->conversation->turns[self::TURN_ID]['message']);
    }

    /**
     * @param list<HarnessEvent> $events
     */
    private static function log(
        array $events,
    ): MemoryEventLog {
        $log = new MemoryEventLog();

        foreach ($events as $event) {
            $log->append($event);
        }

        return $log;
    }

    /**
     * @param list<HarnessEvent> $events
     */
    private static function project(
        array $events,
    ): ProjectionSet {
        $projections = ProjectionSet::empty(self::SESSION_ID);

        foreach ($events as $event) {
            $projections = $projections->apply($event);
        }

        return $projections;
    }

    private static function userEvent(
        int $sequence,
    ): HarnessEvent {
        return HarnessEvent::marker(
            id: "event.{$sequence}",
            sessionId: self::SESSION_ID,
            sequence: $sequence,
            cueType: 'agora.turn.user_message',
            source: EventSource::Agora,
            occurredAt: self::at($sequence),
            payload: ['user_text' => 'Explain replay.'],
            turnId: self::TURN_ID,
        );
    }

    private static function token(
        int $sequence,
        string $text,
        Channel $channel,
    ): HarnessEvent {
        return HarnessEvent::fromCue(
            cue: new TokenDelta(
                id: "cue.{$sequence}",
                sequence: $sequence,
                activityId: 'activity.replay',
                invocationId: 'invocation.replay',
                agentId: 'agent.replay',
                at: self::at($sequence),
                text: $text,
                channel: $channel,
            ),
            sessionId: self::SESSION_ID,
            sequence: $sequence,
            turnId: self::TURN_ID,
            id: "event.{$sequence}",
        );
    }

    private static function at(
        int $sequence,
    ): DateTimeImmutable {
        return new DateTimeImmutable(sprintf('2026-05-24T12:00:%02dZ', $sequence));
    }
}

final class StaticCheckpointReader implements ProjectionCheckpointReader
{
    public function __construct(
        private ?ProjectionSet $projectionSet,
    ) {
    }

    public function latestProjectionSet(
        string $sessionId,
    ): ?ProjectionSet {
        return $this->projectionSet;
    }
}

final class SpyEventReader implements EventReader
{
    /** @var list<int> */
    public array $requestedSequences = [];

    /**
     * @param list<HarnessEvent> $events
     */
    public function __construct(
        private array $events,
    ) {
    }

    public function readAfter(
        string $sessionId,
        int $sequence,
    ): iterable {
        $this->requestedSequences[] = $sequence;

        foreach ($this->events as $event) {
            if ($event->sequence > $sequence) {
                yield $event;
            }
        }
    }
}
