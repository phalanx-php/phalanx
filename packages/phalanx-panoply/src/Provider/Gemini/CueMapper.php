<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Gemini;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Invocation\Completed;
use Phalanx\Panoply\Cue\Invocation\Failed;
use Phalanx\Panoply\Cue\Invocation\Started;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Provider\Resolved;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Sse\Event;

/**
 * Stateful mapper that translates Gemini Generative Language API SSE chunks
 * into panoply {@see Cue} instances.
 *
 * Gemini emits typeless SSE events — the `event:` field is absent on data
 * chunks. Each `data: {...}` payload is a complete response snapshot, NOT
 * a delta against a previous state. The {@see \Phalanx\Panoply\Sse\Parser}
 * passes these through with empty `$event->type`; this mapper dispatches on
 * JSON payload shape.
 *
 * Each chunk carries `candidates[0].content.parts[]`. Parts are dispatched
 * by shape:
 * - `{text: "..."}` → {@see TokenDelta} on {@see Channel::Message}
 * - `{thought: "..."}` or `{thought: true, text: "..."}` → {@see TokenDelta}
 *   on {@see Channel::Reasoning} (Gemini 2.5+ extended-thinking; observed shape
 *   uses the `thought` key present alongside `text`).
 * - `{functionCall: {name, args}}` → {@see Requested} (Gemini emits the full
 *   function call in one chunk — no streamed argument deltas).
 *
 * `finishReason` in `candidates[0]` triggers {@see TokenStop}.
 * `usageMetadata` triggers {@see FinalUsage} + {@see Completed}.
 * A top-level `error` object triggers {@see Failed}.
 *
 * One instance covers exactly one invocation. Create a fresh CueMapper
 * per {@see Provider::perform()} call.
 *
 * Final — sealed stateful mapper; the sequence counter and completion guard
 * are correctness properties that subclasses cannot safely alter.
 */
final class CueMapper
{
    private int $sequence = 0;

    /** Set to true when the first non-empty candidate arrives. */
    private bool $started = false;

    /**
     * Set to true when a finishReason chunk is processed and TokenStop is
     * emitted.
     */
    private bool $finished = false;

    /**
     * Set to true after FinalUsage + Completed are emitted. Prevents double
     * emission if usageMetadata and finishReason appear in the same chunk or
     * complete() is called defensively.
     */
    private bool $completed = false;

    private ?StopReason $pendingStopReason = null;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    public function __construct(
        private(set) Invocation $invocation,
    ) {
    }

    /**
     * Translate one SSE event into zero or more Cues.
     *
     * @return \Generator<int, Cue>
     */
    public function translate(Event $event): \Generator
    {
        // Gemini data chunks carry no event type — Anthropic-style typed events
        // are absent. Skip any chunk that somehow carries a non-empty type.
        if ($event->type !== '') {
            return;
        }

        /** @var array<string, mixed> $data */
        $data = $event->data;

        $now = new \DateTimeImmutable();

        // Top-level error object — emit Failed and return.
        if (isset($data['error']) && is_array($data['error'])) {
            yield from $this->onError($data['error'], $now);

            return;
        }

        /** @var list<array<string, mixed>> $candidates */
        $candidates = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];

        if ($candidates !== []) {
            /** @var array<string, mixed> $candidate */
            $candidate = $candidates[0];

            /** @var array<string, mixed>|null $content */
            $content = is_array($candidate['content'] ?? null) ? $candidate['content'] : null;

            /** @var list<array<string, mixed>> $parts */
            $parts = ($content !== null && is_array($content['parts'] ?? null))
                ? array_values($content['parts'])
                : [];

            if ($parts !== [] && !$this->started) {
                $this->started = true;
                $model = (string) ($data['modelVersion'] ?? '');

                yield $this->resolved($model, $now);
                yield $this->invocationStarted($now);
            }

            foreach ($parts as $part) {
                yield from $this->onPart($part, $now);
            }

            $rawFinish = $candidate['finishReason'] ?? null;
            if (is_string($rawFinish) && $rawFinish !== '') {
                $this->pendingStopReason = self::translateFinishReason($rawFinish);
                $this->finished = true;

                yield new TokenStop(
                    id: (string) Id::ulid(),
                    sequence: $this->sequence++,
                    activityId: $this->invocation->activityId,
                    invocationId: $this->invocation->id,
                    agentId: $this->invocation->agentId,
                    at: $now,
                    reason: $this->pendingStopReason,
                    channel: Channel::Message,
                );
            }
        }

        // usageMetadata may appear alongside candidates or alone in the final chunk.
        if (isset($data['usageMetadata']) && is_array($data['usageMetadata'])) {
            $this->inputTokens = (int) ($data['usageMetadata']['promptTokenCount'] ?? 0);
            $this->outputTokens = (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0);

            if ($this->finished && !$this->completed) {
                yield from $this->emitTerminal($now);
            }
        }
    }

    /**
     * Guarded post-loop terminator. Emits FinalUsage + Completed only when
     * the stream started ($started === true) and has not yet been completed.
     *
     * @return \Generator<int, Cue>
     */
    public function complete(): \Generator
    {
        if (!$this->started || $this->completed) {
            return;
        }

        yield from $this->emitTerminal(new \DateTimeImmutable());
    }

    private static function translateFinishReason(string $raw): StopReason
    {
        return match ($raw) {
            'STOP' => StopReason::EndOfTurn,
            'MAX_TOKENS' => StopReason::MaxTokens,
            default => StopReason::Error,
        };
    }

    /**
     * @param array<string, mixed> $part
     * @return \Generator<int, Cue>
     */
    private function onPart(array $part, \DateTimeImmutable $now): \Generator
    {
        // Gemini 2.5 thinking: part carries a `thought` key (boolean true or
        // non-empty string). The text content is in the `text` field alongside it.
        // Observed shape: {"thought": true, "text": "reasoning text here"}
        if (isset($part['thought'])) {
            $text = (string) ($part['text'] ?? '');
            if ($text !== '') {
                yield new TokenDelta(
                    id: (string) Id::ulid(),
                    sequence: $this->sequence++,
                    activityId: $this->invocation->activityId,
                    invocationId: $this->invocation->id,
                    agentId: $this->invocation->agentId,
                    at: $now,
                    text: $text,
                    channel: Channel::Thinking,
                );
            }

            return;
        }

        // Function call part — complete call emitted in one chunk.
        if (isset($part['functionCall']) && is_array($part['functionCall'])) {
            $fc = $part['functionCall'];
            $name = (string) ($fc['name'] ?? 'unknown');
            $args = is_array($fc['args'] ?? null) ? $fc['args'] : [];
            $effectId = 'fc_' . (string) Id::ulid();

            yield new Requested(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                effectId: $effectId,
                kind: Kind::Custom,
                summary: "tool call: {$name}",
                arguments: $args,
                requiresApproval: false,
            );

            return;
        }

        // Plain text part.
        $text = (string) ($part['text'] ?? '');
        if ($text !== '') {
            yield new TokenDelta(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                text: $text,
                channel: Channel::Message,
            );
        }
    }

    /**
     * @param array<string, mixed> $error
     * @return \Generator<int, Cue>
     */
    private function onError(array $error, \DateTimeImmutable $now): \Generator
    {
        $message = (string) ($error['message'] ?? 'unknown Gemini error');
        $code = isset($error['status']) ? (string) $error['status'] : null;

        $this->completed = true;

        yield new Failed(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            reason: $message,
            errorClass: $code,
        );
    }

    /**
     * Emits FinalUsage + Completed and marks $completed = true.
     * Must only be called after verifying !$this->completed.
     *
     * @return \Generator<int, Cue>
     */
    private function emitTerminal(\DateTimeImmutable $now): \Generator
    {
        $this->completed = true;

        yield new FinalUsage(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
        );

        yield new Completed(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            stopReason: $this->pendingStopReason ?? StopReason::EndOfTurn,
        );
    }

    private function resolved(string $model, \DateTimeImmutable $now): Resolved
    {
        return new Resolved(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            provider: 'gemini',
            model: $model,
            reasonCode: 'invocation',
        );
    }

    private function invocationStarted(\DateTimeImmutable $now): Started
    {
        return new Started(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
        );
    }
}
