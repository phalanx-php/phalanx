<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Plans;

use Phalanx\Tui\Collab\Messages\Envelope;

final class WorkResult
{
    /** @var list<Envelope> */
    private(set) array $envelopes;

    /**
     * @param list<Envelope> $envelopes
     */
    private function __construct(
        private(set) string $itemId,
        private(set) WorkResultStatus $status,
        private(set) mixed $payload = null,
        private(set) ?string $summary = null,
        private(set) ?\Throwable $error = null,
        array $envelopes = [],
    ) {
        if (trim($this->itemId) === '') {
            throw new \InvalidArgumentException('Work result item id cannot be empty.');
        }

        $this->envelopes = array_values($envelopes);
    }

    /**
     * @param list<Envelope> $envelopes
     */
    public static function done(
        string $itemId,
        mixed $payload = null,
        ?string $summary = null,
        array $envelopes = [],
    ): self {
        return new self(
            itemId: $itemId,
            status: WorkResultStatus::Done,
            payload: $payload,
            summary: $summary,
            envelopes: $envelopes,
        );
    }

    public static function blocked(string $itemId, string $reason): self
    {
        return new self(
            itemId: $itemId,
            status: WorkResultStatus::Blocked,
            summary: self::requireReason($reason),
        );
    }

    public static function failed(string $itemId, \Throwable $error): self
    {
        return new self(
            itemId: $itemId,
            status: WorkResultStatus::Failed,
            summary: $error->getMessage(),
            error: $error,
        );
    }

    public function isDone(): bool
    {
        return $this->status === WorkResultStatus::Done;
    }

    public function isBlocked(): bool
    {
        return $this->status === WorkResultStatus::Blocked;
    }

    public function isFailed(): bool
    {
        return $this->status === WorkResultStatus::Failed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'item_id' => $this->itemId,
            'status' => $this->status,
            'payload' => $this->payload,
            'summary' => $this->summary,
            'error' => $this->error === null ? null : [
                'class' => $this->error::class,
                'message' => $this->error->getMessage(),
            ],
            'envelopes' => array_map(
                static fn (Envelope $envelope): array => $envelope->toCanonical(),
                $this->envelopes,
            ),
        ];
    }

    private static function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Blocked work result reason cannot be empty.');
        }

        return $reason;
    }
}
