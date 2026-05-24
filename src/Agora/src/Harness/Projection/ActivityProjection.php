<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Projection;

use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionKind;
use Phalanx\Agora\Harness\ResumePoint;
use Phalanx\Agora\Harness\ResumeStatus;

final class ActivityProjection extends EventProjection
{
    /**
     * @param array<string, int|float|null> $usage
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $sessionId,
        int $eventSequence = 0,
        private(set) ResumeStatus $status = ResumeStatus::Ready,
        private(set) ?string $turnId = null,
        private(set) ?string $pendingEffectRecordId = null,
        private(set) array $usage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'total_tokens' => 0,
            'cost_usd' => null,
        ],
        private(set) array $context = [],
    ) {
        parent::__construct($sessionId, $eventSequence);
    }

    public function kind(): ProjectionKind
    {
        return ProjectionKind::Activity;
    }

    public function resumePoint(
        ?\DateTimeImmutable $updatedAt = null,
    ): ResumePoint {
        return new ResumePoint(
            sessionId: $this->sessionId,
            turnId: $this->turnId,
            eventSequence: $this->eventSequence,
            status: $this->status,
            pendingEffectRecordId: $this->pendingEffectRecordId,
            serializedContext: $this->context,
            updatedAt: $updatedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'session_id' => $this->sessionId,
            'event_sequence' => $this->eventSequence,
            'status' => $this->status->value,
            'turn_id' => $this->turnId,
            'pending_effect_record_id' => $this->pendingEffectRecordId,
            'usage' => $this->usage,
            'context' => $this->context,
        ];
    }

    protected function applyEvent(
        HarnessEvent $event,
    ): void {
        $this->turnId = $event->turnId ?? $this->turnId;

        match ($event->cueType) {
            'cue.invocation.started' => $this->status = ResumeStatus::Streaming,
            'cue.invocation.completed' => $this->status = ResumeStatus::Ready,
            'cue.invocation.failed' => $this->status = ResumeStatus::Failed,
            'cue.invocation.cancelled' => $this->status = ResumeStatus::Cancelled,
            'cue.effect.paused' => $this->pauseForEffect($event),
            'cue.usage.delta' => $this->addUsageDelta($event),
            'cue.usage.final' => $this->recordFinalUsage($event),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function intField(
        array $payload,
        string $field,
    ): int {
        $value = $payload[$field] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /** @return array<string, mixed> */
    private static function cuePayload(
        HarnessEvent $event,
    ): array {
        $payload = $event->payload['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, int|float|null> $usage
     */
    private static function totalTokens(
        array $usage,
    ): int {
        return (int) $usage['input_tokens']
            + (int) $usage['output_tokens']
            + (int) $usage['cache_read_tokens']
            + (int) $usage['cache_write_tokens'];
    }

    private function pauseForEffect(
        HarnessEvent $event,
    ): void {
        $payload = self::cuePayload($event);
        $effectId = $payload['effect_id'] ?? null;

        $this->status = ResumeStatus::WaitingApproval;
        $this->pendingEffectRecordId = is_string($payload['effect_record_id'] ?? null)
            ? $payload['effect_record_id']
            : $event->id;

        if (is_string($effectId)) {
            $this->context['pending_effect_id'] = $effectId;
        }
    }

    private function addUsageDelta(
        HarnessEvent $event,
    ): void {
        $payload = self::cuePayload($event);

        $this->usage['input_tokens'] += self::intField($payload, 'input_tokens');
        $this->usage['output_tokens'] += self::intField($payload, 'output_tokens');
        $this->usage['cache_read_tokens'] += self::intField($payload, 'cache_read_tokens');
        $this->usage['cache_write_tokens'] += self::intField($payload, 'cache_write_tokens');
        $this->usage['total_tokens'] = self::totalTokens($this->usage);
    }

    private function recordFinalUsage(
        HarnessEvent $event,
    ): void {
        $payload = self::cuePayload($event);
        $this->usage = [
            'input_tokens' => self::intField($payload, 'input_tokens'),
            'output_tokens' => self::intField($payload, 'output_tokens'),
            'cache_read_tokens' => self::intField($payload, 'cache_read_tokens'),
            'cache_write_tokens' => self::intField($payload, 'cache_write_tokens'),
            'total_tokens' => 0,
            'cost_usd' => $payload['cost_usd'] ?? null,
        ];
        $this->usage['total_tokens'] = self::totalTokens($this->usage);
    }
}
