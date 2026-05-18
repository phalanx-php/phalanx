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
 * Stateful mapper that translates OpenAI Responses API SSE events into
 * panoply {@see Cue} instances.
 *
 * The Responses API uses named SSE events (the `event:` field is present),
 * matching the Anthropic pattern. Each event type maps to one or more Cues
 * per the v0 spec mapping table.
 *
 * One instance covers exactly one invocation. Create a fresh ResponsesCueMapper
 * per {@see ResponsesProvider::perform()} call.
 *
 * Final — sealed stateful mapper; the sequence counter and effect-id registry
 * are correctness properties that subclasses cannot safely alter.
 */
final class ResponsesCueMapper
{
    private int $sequence = 0;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    /** Set to true when response.created fires, emitting Resolved + Started. */
    private bool $started = false;

    /**
     * Set to true after TokenStop + FinalUsage + Completed are emitted — either
     * via the wire-native response.completed path or the defensive complete()
     * path. Prevents double emission regardless of which path fires first.
     */
    private bool $completed = false;

    /** Set to true when {@see onFunctionCallCreated} fires at least once. */
    private bool $hasToolCalls = false;

    /**
     * Maps function call item_id → effectId for argument delta routing.
     *
     * @var array<string, string>
     */
    private array $functionCallEffectIds = [];

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

        if ($event->type === 'response.created') {
            yield from $this->onResponseCreated($event, $now);
        } elseif ($event->type === 'response.output_text.delta') {
            yield from $this->onOutputTextDelta($event, $now);
        } elseif ($event->type === 'response.reasoning.delta') {
            yield from $this->onReasoningDelta($event, $now);
        } elseif ($event->type === 'response.function_call.created') {
            yield from $this->onFunctionCallCreated($event, $now);
        } elseif ($event->type === 'response.function_call_arguments.delta') {
            yield from $this->onFunctionCallArgumentsDelta($event, $now);
        } elseif ($event->type === 'response.completed') {
            yield from $this->onResponseCompleted($event, $now);
        } elseif ($event->type === 'response.failed') {
            yield from $this->onResponseFailed($event, $now);
        }
        // Unknown event types yield nothing — forward compatibility.
    }

    /**
     * Guarded post-loop terminator. Called by the provider after the parser
     * flush. Emits TokenStop + FinalUsage + Completed only when the stream
     * actually started ($started === true) and completion has not already been
     * emitted ($completed === false).
     *
     * Handles transport truncation and mid-stream cancellation where the wire-
     * native response.completed event never arrives.
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

    /**
     * @return \Generator<int, Cue>
     */
    private function onResponseCreated(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{response?: array{model?: string}} $data */
        $data  = $event->data;
        $model = (string) ($data['response']['model'] ?? '');

        $this->started = true;

        yield new Resolved(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            provider: 'openai',
            model: $model,
            reasonCode: 'invocation',
        );

        yield new Started(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
        );
    }

    /**
     * @return \Generator<int, Cue>
     */
    private function onOutputTextDelta(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{delta?: string} $data */
        $data  = $event->data;
        $delta = (string) ($data['delta'] ?? '');

        if ($delta === '') {
            return;
        }

        yield new TokenDelta(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            text: $delta,
            channel: Channel::Message,
        );
    }

    /**
     * @return \Generator<int, Cue>
     */
    private function onReasoningDelta(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{delta?: string} $data */
        $data  = $event->data;
        $delta = (string) ($data['delta'] ?? '');

        if ($delta === '') {
            return;
        }

        yield new TokenDelta(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            text: $delta,
            channel: Channel::Reasoning,
        );
    }

    /**
     * @return \Generator<int, Cue>
     */
    private function onFunctionCallCreated(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{item?: array{id?: string, name?: string}} $data */
        $data     = $event->data;
        $item     = is_array($data['item'] ?? null) ? $data['item'] : [];
        $rawId    = (string) ($item['id'] ?? Id::generate());
        $effectId = 'fc_' . $rawId;
        $name     = (string) ($item['name'] ?? 'unknown');

        $this->functionCallEffectIds[$rawId] = $effectId;
        $this->hasToolCalls                  = true;

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

    /**
     * @return \Generator<int, Cue>
     */
    private function onFunctionCallArgumentsDelta(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{item_id?: string, delta?: string} $data */
        $data      = $event->data;
        $rawId     = (string) ($data['item_id'] ?? '');
        $jsonDelta = (string) ($data['delta'] ?? '');

        if ($jsonDelta === '') {
            return;
        }

        $effectId = $this->functionCallEffectIds[$rawId] ?? 'fc_' . $rawId;

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

    /**
     * @return \Generator<int, Cue>
     */
    private function onResponseCompleted(Event $event, \DateTimeImmutable $now): \Generator
    {
        if ($this->completed) {
            return;
        }

        /** @var array{response?: array{usage?: array{input_tokens?: int, output_tokens?: int}}} $data */
        $data  = $event->data;
        $usage = is_array($data['response']['usage'] ?? null) ? $data['response']['usage'] : [];

        $this->inputTokens  = (int) ($usage['input_tokens'] ?? 0);
        $this->outputTokens = (int) ($usage['output_tokens'] ?? 0);

        yield from $this->emitTerminal($now);
    }

    /**
     * Emits TokenStop + FinalUsage + Completed and marks $completed = true.
     * Must only be called after verifying !$this->completed.
     *
     * @return \Generator<int, Cue>
     */
    private function emitTerminal(\DateTimeImmutable $now): \Generator
    {
        $this->completed = true;
        $stopReason      = $this->hasToolCalls ? StopReason::ToolUse : StopReason::EndOfTurn;

        yield new TokenStop(
            id: (string) Id::ulid(),
            sequence: $this->sequence++,
            activityId: $this->invocation->activityId,
            invocationId: $this->invocation->id,
            agentId: $this->invocation->agentId,
            at: $now,
            reason: $stopReason,
            channel: Channel::Message,
        );

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
            stopReason: $stopReason,
        );
    }

    /**
     * Handles the `response.failed` event emitted by the OpenAI Responses API
     * when a request fails at the provider level. Error info lives at
     * `data.response.error` (canonical path) with a fallback to top-level
     * `data.message` / `data.code` for error-only event shapes.
     *
     * @return \Generator<int, Cue>
     */
    private function onResponseFailed(Event $event, \DateTimeImmutable $now): \Generator
    {
        /** @var array{response?: array{error?: array{message?: string, code?: string}}, message?: string, code?: string} $data */
        $data = $event->data;

        // OpenAI Responses API error shape: response.failed carries error info
        // under data.response.error (the full response object with error populated).
        $responseError = is_array($data['response']['error'] ?? null)
            ? $data['response']['error']
            : [];

        $message = (string) ($responseError['message'] ?? $data['message'] ?? 'unknown provider error');
        $code    = isset($responseError['code'])
            ? (string) $responseError['code']
            : (isset($data['code']) ? (string) $data['code'] : null);

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
}
