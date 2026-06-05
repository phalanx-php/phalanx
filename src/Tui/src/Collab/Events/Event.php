<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Events;

use DateTimeImmutable;
use Phalanx\Tui\Collab\Internal\CanonicalHash;
use Phalanx\Tui\Collab\Internal\Id;
use Phalanx\Tui\Collab\Messages\Envelope;
use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\Reviews\ReviewVerdict;

final class Event
{
    /** @var array<string, mixed> */
    private(set) array $context;

    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private(set) string $id,
        private(set) EventKind $kind,
        private(set) DateTimeImmutable $occurredAt,
        private(set) ?Envelope $envelope = null,
        private(set) ?WorkItem $workItem = null,
        private(set) ?WorkResult $workResult = null,
        private(set) ?ReviewVerdict $reviewVerdict = null,
        array $context = [],
    ) {
        if (trim($this->id) === '') {
            throw new \InvalidArgumentException('Collab event id cannot be empty.');
        }

        $this->context = $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function record(
        EventKind $kind,
        ?Envelope $envelope = null,
        ?WorkItem $workItem = null,
        ?WorkResult $workResult = null,
        ?ReviewVerdict $reviewVerdict = null,
        array $context = [],
        ?DateTimeImmutable $occurredAt = null,
        ?string $id = null,
    ): self {
        return new self(
            id: $id ?? self::newId(),
            kind: $kind,
            occurredAt: $occurredAt ?? new DateTimeImmutable(),
            envelope: $envelope,
            workItem: $workItem,
            workResult: $workResult,
            reviewVerdict: $reviewVerdict,
            context: $context,
        );
    }

    public function routable(?string $summary = null): RoutableEvent
    {
        return new RoutableEvent(
            id: $this->id,
            kind: $this->kind,
            occurredAt: $this->occurredAt,
            envelope: $this->envelope,
            workItem: $this->workItem,
            workResult: $this->workResult,
            reviewVerdict: $this->reviewVerdict,
            summary: $summary,
        );
    }

    public function hash(): string
    {
        return CanonicalHash::of($this->toCanonical());
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'envelope' => $this->envelope?->toCanonical(),
            'work_item' => $this->workItem?->toCanonical(),
            'work_result' => $this->workResult?->toCanonical(),
            'review_verdict' => $this->reviewVerdict?->toCanonical(),
            'context' => $this->context,
        ];
    }

    private static function newId(): string
    {
        return Id::new('evt');
    }
}
