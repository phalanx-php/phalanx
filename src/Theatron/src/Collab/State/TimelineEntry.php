<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

use DateTimeImmutable;
use Phalanx\Theatron\Collab\Events\AgentHarnessEvent;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Messages\MessageKind;

final class TimelineEntry
{
    public function __construct(
        private(set) string $id,
        private(set) TimelineEntryKind $kind,
        private(set) DateTimeImmutable $occurredAt,
        private(set) string $summary,
        private(set) ?string $eventId = null,
        private(set) ?string $envelopeId = null,
        private(set) ?string $workItemId = null,
        private(set) ?string $status = null,
    ) {
        if (trim($this->id) === '') {
            throw new \InvalidArgumentException('Timeline entry id cannot be empty.');
        }

        if (trim($this->summary) === '') {
            throw new \InvalidArgumentException('Timeline entry summary cannot be empty.');
        }

        if ($this->eventId !== null && trim($this->eventId) === '') {
            throw new \InvalidArgumentException('Timeline entry event id cannot be empty.');
        }

        if ($this->envelopeId !== null && trim($this->envelopeId) === '') {
            throw new \InvalidArgumentException('Timeline entry envelope id cannot be empty.');
        }

        if ($this->workItemId !== null && trim($this->workItemId) === '') {
            throw new \InvalidArgumentException('Timeline entry work item id cannot be empty.');
        }

        if ($this->status !== null && trim($this->status) === '') {
            throw new \InvalidArgumentException('Timeline entry status cannot be empty.');
        }
    }

    public static function fromEnvelope(AgentHarnessEvent $event, Envelope $envelope): self
    {
        return new self(
            id: "{$event->id}:{$envelope->id}",
            kind: self::kindForEnvelope($envelope),
            occurredAt: $event->occurredAt,
            summary: self::summaryForEnvelope($envelope),
            eventId: $event->id,
            envelopeId: $envelope->id,
            status: $envelope->kind->value,
        );
    }

    public static function work(AgentHarnessEvent $event, TimelineEntryKind $kind, string $summary): self
    {
        $workItem = $event->workItem ?? self::missing('work item', $event);

        return new self(
            id: "{$event->id}:{$kind->value}:{$workItem->id}",
            kind: $kind,
            occurredAt: $event->occurredAt,
            summary: $summary,
            eventId: $event->id,
            workItemId: $workItem->id,
            status: $event->workResult?->status->value,
        );
    }

    public static function review(AgentHarnessEvent $event): self
    {
        $verdict = $event->reviewVerdict ?? self::missing('review verdict', $event);

        return new self(
            id: "{$event->id}:review",
            kind: TimelineEntryKind::Review,
            occurredAt: $event->occurredAt,
            summary: $verdict->reason ?? 'Review approved.',
            eventId: $event->id,
            status: $verdict->status->value,
        );
    }

    private static function kindForEnvelope(Envelope $envelope): TimelineEntryKind
    {
        return match ($envelope->kind) {
            MessageKind::Prompt => TimelineEntryKind::Prompt,
            MessageKind::Response => TimelineEntryKind::Response,
            default => TimelineEntryKind::Message,
        };
    }

    private static function summaryForEnvelope(Envelope $envelope): string
    {
        if (is_string($envelope->payload) && trim($envelope->payload) !== '') {
            return trim($envelope->payload);
        }

        return $envelope->kind->value;
    }

    private static function missing(string $field, AgentHarnessEvent $event): never
    {
        throw new \InvalidArgumentException(sprintf(
            'AgentHarness event "%s" requires a %s for timeline projection.',
            $event->kind->value,
            $field,
        ));
    }
}
