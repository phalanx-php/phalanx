<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\State;

use Phalanx\Tui\Runtime\Events\Event;
use Phalanx\Tui\Runtime\Events\EventKind;
use Phalanx\Tui\Runtime\Messages\Envelope;

final class MessageTimelineSlice
{
    /** @var list<Envelope> */
    private(set) array $envelopes;

    /** @var list<TimelineEntry> */
    private(set) array $entries;

    /**
     * @param list<Envelope> $envelopes
     * @param list<TimelineEntry> $entries
     */
    public function __construct(
        array $envelopes = [],
        array $entries = [],
    ) {
        $this->envelopes = array_values($envelopes);
        $this->entries = $entries === []
            ? array_map(
                static fn (Envelope $envelope): TimelineEntry => TimelineEntry::fromEnvelope(
                    Event::record(EventKind::WorkReceived, envelope: $envelope),
                    $envelope,
                ),
                $this->envelopes,
            )
            : array_values($entries);
    }

    public function record(Envelope $envelope): self
    {
        $event = Event::record(EventKind::WorkReceived, envelope: $envelope);

        return new self(
            envelopes: [...$this->envelopes, $envelope],
            entries: [...$this->entries, TimelineEntry::fromEnvelope($event, $envelope)],
        );
    }

    public function project(Event $event): self
    {
        $envelopes = $this->envelopes;
        $entries = $this->entries;

        if ($event->envelope !== null) {
            $envelopes[] = $event->envelope;
            $entries[] = TimelineEntry::fromEnvelope($event, $event->envelope);
        }

        if ($event->workResult !== null) {
            foreach ($event->workResult->envelopes as $envelope) {
                $envelopes[] = $envelope;
                $entries[] = TimelineEntry::fromEnvelope($event, $envelope);
            }
        }

        $workEntry = self::workEntry($event);
        if ($workEntry !== null) {
            $entries[] = $workEntry;
        }

        if ($event->kind === EventKind::WorkReviewed) {
            $entries[] = TimelineEntry::review($event);
        }

        return new self($envelopes, $entries);
    }

    private static function workEntry(Event $event): ?TimelineEntry
    {
        return match ($event->kind) {
            EventKind::WorkItemStarted => TimelineEntry::work(
                $event,
                TimelineEntryKind::WorkStarted,
                sprintf('Started %s.', self::workItemId($event)),
            ),
            EventKind::WorkItemCompleted => TimelineEntry::work(
                $event,
                TimelineEntryKind::WorkCompleted,
                self::workResultSummary($event) ?? sprintf('Completed %s.', self::workItemId($event)),
            ),
            EventKind::WorkInterrupted => TimelineEntry::work(
                $event,
                TimelineEntryKind::WorkInterrupted,
                self::workResultSummary($event) ?? sprintf('Interrupted %s.', self::workItemId($event)),
            ),
            default => null,
        };
    }

    private static function workResultSummary(Event $event): ?string
    {
        if ($event->workResult === null) {
            throw new \InvalidArgumentException(sprintf(
                'Runtime event "%s" requires a work result for timeline projection.',
                $event->kind->value,
            ));
        }

        $summary = trim((string) $event->workResult->summary);

        return $summary === '' ? null : $summary;
    }

    private static function workItemId(Event $event): string
    {
        if ($event->workItem === null) {
            throw new \InvalidArgumentException(sprintf(
                'Runtime event "%s" requires a work item for timeline projection.',
                $event->kind->value,
            ));
        }

        return $event->workItem->id;
    }
}
