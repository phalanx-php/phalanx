<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Messages\Envelope;

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
                    CollabEvent::record(EventKind::WorkReceived, envelope: $envelope),
                    $envelope,
                ),
                $this->envelopes,
            )
            : array_values($entries);
    }

    public function record(Envelope $envelope): self
    {
        $event = CollabEvent::record(EventKind::WorkReceived, envelope: $envelope);

        return new self(
            envelopes: [...$this->envelopes, $envelope],
            entries: [...$this->entries, TimelineEntry::fromEnvelope($event, $envelope)],
        );
    }

    public function project(CollabEvent $event): self
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

    private static function workEntry(CollabEvent $event): ?TimelineEntry
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

    private static function workResultSummary(CollabEvent $event): ?string
    {
        if ($event->workResult === null) {
            throw new \InvalidArgumentException(sprintf(
                'Collab event "%s" requires a work result for timeline projection.',
                $event->kind->value,
            ));
        }

        return $event->workResult->summary;
    }

    private static function workItemId(CollabEvent $event): string
    {
        if ($event->workItem === null) {
            throw new \InvalidArgumentException(sprintf(
                'Collab event "%s" requires a work item for timeline projection.',
                $event->kind->value,
            ));
        }

        return $event->workItem->id;
    }
}
