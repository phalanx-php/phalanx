<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Anthropic;

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
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Sse\Event;

/**
 * Stateful mapper that translates Anthropic SSE events into panoply
 * {@see Cue} instances. Tracks the active content block index to map
 * `content_block_delta` events onto the correct block type — text blocks
 * emit {@see TokenDelta} cues; tool-use blocks emit {@see ArgumentsDelta}
 * cues with the accumulated partial JSON.
 *
 * One instance covers exactly one invocation. Create a fresh CueMapper
 * per {@see Provider::perform()} call.
 *
 * Final — sealed stateful mapper; the sequence counter and block-state
 * invariants are correctness properties that subclasses cannot safely alter.
 */
final class CueMapper
{
    private int $sequence = 0;

    /**
     * Tracks open content blocks by index.
     * Each entry: ['type' => 'text'|'tool_use', 'effectId' => string|null]
     *
     * @var array<int, array{type: string, effectId: string|null}>
     */
    private array $activeBlocks = [];

    /** Set to true when message_start fires, emitting Resolved + Started. */
    private bool $started = false;

    /**
     * Set to true after FinalUsage + Completed are emitted — either via the
     * wire-native message_stop path or the defensive complete() path.
     * Prevents double emission regardless of which path fires first.
     */
    private bool $completed = false;

    /** Accumulated stop reason from message_delta; applied in message_stop */
    private ?StopReason $pendingStopReason = null;

    /** Input token count from message_start.message.usage.input_tokens */
    private int $inputTokens = 0;

    /** Output token count accumulated from message_delta.usage.output_tokens */
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
        $now = new \DateTimeImmutable();

        if ($event->type === 'message_start') {
            yield from $this->onMessageStart($event, $now);
        } elseif ($event->type === 'content_block_start') {
            yield from $this->onContentBlockStart($event, $now);
        } elseif ($event->type === 'content_block_delta') {
            yield from $this->onContentBlockDelta($event, $now);
        } elseif ($event->type === 'message_delta') {
            yield from $this->onMessageDelta($event, $now);
        } elseif ($event->type === 'message_stop') {
            yield from $this->onMessageStop($event, $now);
        } elseif ($event->type === 'error') {
            yield from $this->onError($event, $now);
        }
        // content_block_stop and unknown event types yield nothing.
    }

    /**
     * Guarded post-loop terminator. Called by the provider after the parser
     * flush. Emits FinalUsage + Completed only when the stream actually started
     * ($started === true) and completion has not already been emitted
     * ($completed === false).
     *
     * Handles transport truncation and mid-stream cancellation where the wire-
     * native message_stop event never arrives.
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

    private static function translateStopReason(string $raw): StopReason
    {
        return match ($raw) {
            'end_turn' => StopReason::EndOfTurn,
            'max_tokens' => StopReason::MaxTokens,
            'stop_sequence' => StopReason::StopSequence,
            'tool_use' => StopReason::ToolUse,
            default => StopReason::EndOfTurn,
        };
    }

    /**
     * @return \Generator<int, Cue>
     */
    private function onMessageStart(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{message?: array{model?: string, usage?: array{input_tokens?: int}}} $data */
        $data = $event->data;
        $model = (string) ($data['message']['model'] ?? '');

        $this->inputTokens = (int) ($data['message']['usage']['input_tokens'] ?? 0);
        $this->started = true;

        yield $this->resolved('anthropic', $model, $now);
        yield $this->invocationStarted($now);
    }

    /**
     * @return \Generator<int, Cue>
     */
    private function onContentBlockStart(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{index?: int, content_block?: array{type?: string, id?: string}} $data */
        $data = $event->data;
        $index = (int) ($data['index'] ?? 0);
        $block = $data['content_block'] ?? [];
        $type = (string) ($block['type'] ?? '');

        if ($type === 'tool_use') {
            $effectId = (string) ($block['id'] ?? Id::generate());
            $this->activeBlocks[$index] = ['type' => 'tool_use', 'effectId' => $effectId];
            $toolName = (string) ($block['name'] ?? 'unknown');

            yield new Requested(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                effectId: $effectId,
                kind: Kind::Custom,
                summary: "tool call: {$toolName}",
                arguments: [],
                requiresApproval: false,
            );
        } else {
            $this->activeBlocks[$index] = ['type' => $type ?: 'text', 'effectId' => null];
        }
    }

    /**
     * @return \Generator<int, Cue>
     */
    private function onContentBlockDelta(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{index?: int, delta?: array{type?: string, text?: string, thinking?: string, partial_json?: string}} $data */
        $data = $event->data;
        $index = (int) ($data['index'] ?? 0);
        $delta = $data['delta'] ?? [];
        $deltaType = (string) ($delta['type'] ?? '');
        $block = $this->activeBlocks[$index] ?? ['type' => 'text', 'effectId' => null];

        if ($deltaType === 'text_delta') {
            $text = (string) ($delta['text'] ?? '');
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
        } elseif ($deltaType === 'thinking_delta') {
            $text = (string) ($delta['thinking'] ?? '');
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
        } elseif ($deltaType === 'input_json_delta') {
            $jsonDelta = (string) ($delta['partial_json'] ?? '');
            $effectId = (string) ($block['effectId'] ?? '');
            if ($jsonDelta !== '' && $effectId !== '') {
                yield new ArgumentsDelta(
                    id: (string) Id::ulid(),
                    sequence: $this->sequence++,
                    activityId: $this->invocation->activityId,
                    invocationId: $this->invocation->id,
                    agentId: $this->invocation->agentId,
                    at: $now,
                    effectId: $effectId,
                    jsonDelta: $jsonDelta,
                );
            }
        }
    }

    /**
     * @return \Generator<int, Cue>
     */
    private function onMessageDelta(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{delta?: array{stop_reason?: string}, usage?: array{output_tokens?: int}} $data */
        $data = $event->data;
        $delta = $data['delta'] ?? [];
        $rawStop = (string) ($delta['stop_reason'] ?? '');
        $usage = $data['usage'] ?? [];

        if ($rawStop !== '') {
            $this->pendingStopReason = self::translateStopReason($rawStop);

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

        $outputTokens = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null;
        if ($outputTokens !== null) {
            $this->outputTokens += $outputTokens;

            yield new UsageDelta(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                inputTokens: 0,
                outputTokens: $outputTokens,
            );
        }
    }

    /**
     * @return \Generator<int, Cue>
     */
    private function onMessageStop(Event $event, \DateTimeImmutable $now): \Generator
    {
        if ($this->completed) {
            return;
        }

        yield from $this->emitTerminal($now);
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
     * @return \Generator<int, Cue>
     */
    private function onError(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{error?: array{message?: string, type?: string}} $data */
        $data = $event->data;
        $error = $data['error'] ?? [];
        $message = (string) ($error['message'] ?? 'unknown provider error');

        $this->completed = true;

        yield new Failed(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            reason: $message,
            errorClass: (string) ($error['type'] ?? null) ?: null,
        );
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
