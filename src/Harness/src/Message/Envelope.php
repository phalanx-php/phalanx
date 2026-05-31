<?php

declare(strict_types=1);

namespace Phalanx\Harness\Message;

use DateTimeImmutable;
use Phalanx\Harness\Boundary\Urgency;
use Phalanx\Harness\Support\CanonicalHash;
use Phalanx\Harness\Support\HarnessId;
use Phalanx\Harness\Support\StringList;

final class Envelope
{
    /** @var list<string> */
    private(set) array $tags;

    /**
     * @param list<string> $tags
     */
    private function __construct(
        private(set) string $id,
        private(set) Address $from,
        private(set) Address $to,
        private(set) MessageKind $kind,
        private(set) mixed $payload,
        private(set) DateTimeImmutable $at,
        private(set) ?string $correlationId = null,
        private(set) int $priority = 0,
        array $tags = [],
    ) {
        if (trim($this->id) === '') {
            throw new \InvalidArgumentException('Envelope id cannot be empty.');
        }

        if ($this->correlationId !== null && trim($this->correlationId) === '') {
            throw new \InvalidArgumentException('Envelope correlation id cannot be empty.');
        }

        $this->tags = StringList::unique($tags);
    }

    /**
     * @param list<string> $tags
     */
    public static function make(
        Address $from,
        Address $to,
        MessageKind $kind,
        mixed $payload = null,
        ?string $correlationId = null,
        int $priority = 0,
        array $tags = [],
        ?DateTimeImmutable $at = null,
        ?string $id = null,
    ): self {
        return new self(
            id: $id ?? self::newId(),
            from: $from,
            to: $to,
            kind: $kind,
            payload: $payload,
            at: $at ?? new DateTimeImmutable(),
            correlationId: $correlationId,
            priority: $priority,
            tags: $tags,
        );
    }

    public static function prompt(string $content, ?Address $to = null): self
    {
        return self::make(
            from: Address::user(),
            to: $to ?? Address::agent('primary'),
            kind: MessageKind::Prompt,
            payload: $content,
        );
    }

    public static function delegate(
        Address $from,
        Address $to,
        mixed $payload,
        ?string $correlationId = null,
    ): self {
        return self::make(
            from: $from,
            to: $to,
            kind: MessageKind::Task,
            payload: $payload,
            correlationId: $correlationId,
        );
    }

    public static function observation(
        string $source,
        mixed $payload,
        Urgency $urgency = Urgency::Queue,
    ): self {
        return self::make(
            from: Address::service($source),
            to: Address::agent('primary'),
            kind: $urgency === Urgency::Queue ? MessageKind::Observation : MessageKind::Alert,
            payload: $payload,
            priority: $urgency->priority(),
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
            'from' => $this->from->toCanonical(),
            'to' => $this->to->toCanonical(),
            'kind' => $this->kind,
            'payload' => $this->payload,
            'at' => $this->at->format(DATE_ATOM),
            'correlation_id' => $this->correlationId,
            'priority' => $this->priority,
            'tags' => $this->tags,
        ];
    }

    private static function newId(): string
    {
        return HarnessId::new('env');
    }
}
