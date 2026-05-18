<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\OpenAI;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta;
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
 * Stateful mapper that translates OpenAI Chat Completions SSE chunks into
 * panoply {@see Cue} instances.
 *
 * OpenAI's Chat Completions wire format uses typeless SSE events — the
 * `event:` field is absent; all type information is embedded in the JSON
 * payload under `object`, `choices[*].delta`, and `finish_reason`. The
 * {@see \Phalanx\Panoply\Sse\Parser} passes these through with an empty
 * `$event->type`; this mapper dispatches on payload shape.
 *
 * Tool call streaming uses a parallel indexed array: `choices[0].delta.tool_calls[i]`.
 * The first chunk for index `i` carries the call `id` and `function.name`;
 * subsequent chunks carry only `function.arguments` partials. This mapper
 * tracks the accumulated effectId per index so argument deltas can reference
 * the correct effect identity.
 *
 * One instance covers exactly one invocation. Create a fresh ChatCueMapper
 * per {@see ChatProvider::perform()} call.
 *
 * Final — sealed stateful mapper; the sequence counter and tool-call-index
 * state are correctness properties that subclasses cannot safely alter.
 */
final class ChatCueMapper
{
    private int $sequence = 0;

    /** Set to true when the first role chunk arrives, emitting Resolved + Started. */
    private bool $started = false;

    /**
     * Set to true when a finish_reason chunk is processed and TokenStop is
     * emitted. Used to distinguish a clean stop from an aborted mid-stream.
     */
    private bool $finished = false;

    /**
     * Set to true after FinalUsage + Completed are emitted (either via the
     * usage-chunk path inside translate() or the defensive complete() path).
     * Prevents double emission regardless of which path fires first.
     */
    private bool $completed = false;

    private ?StopReason $pendingStopReason = null;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    /**
     * Tracks effectId per tool_calls index for streaming argument assembly.
     *
     * @var array<int, string>
     */
    private array $toolCallEffectIds = [];

    public function __construct(
        private(set) Invocation $invocation,
        private(set) string $providerId = 'openai',
    ) {
    }

    /**
     * Translate one SSE event into zero or more Cues.
     *
     * @return \Generator<int, Cue>
     */
    public function translate(Event $event): \Generator
    {
        // OpenAI Chat Completions omits the event: field — type is always ''.
        // Responses API uses named events; this mapper only handles ''.
        if ($event->type !== '') {
            return;
        }

        /** @var array<string, mixed> $data */
        $data = $event->data;

        $now = new \DateTimeImmutable();

        // Top-level error object — emitted mid-stream when the provider rejects
        // the request after streaming has begun (rate limit, content filter, etc.).
        if (isset($data['error']) && is_array($data['error']) && !isset($data['choices'])) {
            /** @var array<string, mixed> $err */
            $err     = $data['error'];
            $message = (string) ($err['message'] ?? 'unknown provider error');
            $code    = isset($err['code']) && is_string($err['code']) ? $err['code'] : null;

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

            return;
        }

        // Usage-only chunk (stream_options: {include_usage: true} final chunk).
        // When $finished is already true (a finish_reason chunk preceded this),
        // this usage chunk is the natural completion trigger. Emit the terminal
        // cues now — complete() becomes a guarded no-op.
        if (isset($data['usage']) && !isset($data['choices'])) {
            $this->accumulateUsage($data);

            if ($this->finished && !$this->completed) {
                yield from $this->emitTerminal($now);
            }

            return;
        }

        /** @var list<array<string, mixed>> $choices */
        $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];

        if ($choices === []) {
            return;
        }

        /** @var array<string, mixed> $choice */
        $choice = $choices[0];

        /** @var array<string, mixed> $delta */
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

        // First chunk: delta.role signals stream start.
        if (!$this->started && isset($delta['role'])) {
            $this->started = true;
            $model         = (string) ($data['model'] ?? '');
            yield $this->resolved($this->providerId, $model, $now);
            yield $this->invocationStarted($now);
        }

        // Text content delta.
        $content = $delta['content'] ?? null;
        if (is_string($content) && $content !== '') {
            yield new TokenDelta(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                text: $content,
                channel: Channel::Message,
            );
        }

        // Reasoning content delta (o1/o3 models).
        $reasoning = $delta['reasoning_content'] ?? null;
        if (is_string($reasoning) && $reasoning !== '') {
            yield new TokenDelta(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                text: $reasoning,
                channel: Channel::Reasoning,
            );
        }

        // Tool call streaming.
        $toolCalls = isset($delta['tool_calls']) && is_array($delta['tool_calls'])
            ? $delta['tool_calls']
            : [];

        foreach ($toolCalls as $tc) {
            if (!is_array($tc)) {
                continue;
            }

            yield from $this->onToolCallChunk($tc, $now);
        }

        // Finish reason — may appear alongside a content delta or alone.
        $finishReason = $choice['finish_reason'] ?? null;
        if (is_string($finishReason) && $finishReason !== '') {
            $this->pendingStopReason = self::translateFinishReason($finishReason);
            $this->finished          = true;

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

        // Usage may appear in the same chunk as finish_reason (no stream_options
        // needed — some providers/models include it inline). Accumulate and, if
        // the stream has already finished, emit the terminal cues immediately.
        if (isset($data['usage']) && is_array($data['usage'])) {
            $this->accumulateUsage($data);

            if ($this->finished && !$this->completed) {
                yield from $this->emitTerminal($now);
            }
        }
    }

    /**
     * Guarded post-loop terminator. Called by the provider after the parser
     * flush. Emits FinalUsage + Completed only when:
     * - The stream actually started ($started === true) — an empty stream or
     *   one carrying only ignored events (e.g. typed Responses API events)
     *   emits nothing, honoring the contract that Completed implies a Started.
     * - Completion has not already been emitted ($completed === false) — covers
     *   the case where a usage-only chunk or inline usage already triggered the
     *   terminal cues inside translate().
     *
     * This path handles streams where the provider emits usage inline alongside
     * finish_reason (the common default) or where the stream is truncated
     * before a clean finish_reason chunk.
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
            'stop'           => StopReason::EndOfTurn,
            'length'         => StopReason::MaxTokens,
            'tool_calls'     => StopReason::ToolUse,
            'content_filter' => StopReason::Error,
            default          => StopReason::EndOfTurn,
        };
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

    /**
     * @param array<string, mixed> $tc
     * @return \Generator<int, Cue>
     */
    private function onToolCallChunk(array $tc, \DateTimeImmutable $now): \Generator
    {
        $index    = isset($tc['index']) ? (int) $tc['index'] : 0;
        $function = is_array($tc['function'] ?? null) ? $tc['function'] : [];

        if (!isset($this->toolCallEffectIds[$index])) {
            // First chunk for this index — carries id and name.
            $rawId    = isset($tc['id']) && is_string($tc['id']) ? $tc['id'] : 'tc_' . $index;
            $effectId = 'tc_' . $rawId;
            $name     = isset($function['name']) && is_string($function['name'])
                ? $function['name']
                : 'unknown';

            $this->toolCallEffectIds[$index] = $effectId;

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
                arguments: [],
                requiresApproval: false,
            );
        }

        // Argument delta (all chunks, including the first if it carries arguments).
        $jsonDelta = isset($function['arguments']) && is_string($function['arguments'])
            ? $function['arguments']
            : '';

        if ($jsonDelta !== '') {
            yield new ArgumentsDelta(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                effectId: $this->toolCallEffectIds[$index],
                jsonDelta: $jsonDelta,
            );
        }
    }

    /**
     * Accumulates usage token counts from a chunk. Usage counts are folded
     * into the {@see FinalUsage} emitted by {@see self::complete()}.
     *
     * @param array<string, mixed> $data full chunk carrying usage
     */
    private function accumulateUsage(array $data): void
    {
        /** @var array<string, mixed> $usage */
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        $this->inputTokens  += (int) ($usage['prompt_tokens'] ?? 0);
        $this->outputTokens += (int) ($usage['completion_tokens'] ?? 0);
    }

    private function resolved(string $provider, string $model, \DateTimeImmutable $now): Resolved
    {
        return new Resolved(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            provider: $provider,
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
