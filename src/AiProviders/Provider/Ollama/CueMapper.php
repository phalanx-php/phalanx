<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider\Ollama;

use Phalanx\AiProviders\Cue;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Cue\Invocation\Completed;
use Phalanx\AiProviders\Cue\Invocation\Failed;
use Phalanx\AiProviders\Cue\Invocation\Started;
use Phalanx\AiProviders\Cue\Output\Channel;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\Provider\Resolved;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Cue\Usage\FinalUsage;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Id;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider\NdjsonStreamingCueMapper;

/**
 * Stateful mapper that translates Ollama NDJSON lines into ai-providers
 * {@see Cue} instances.
 *
 * Ollama's `/api/chat` streaming response emits one JSON object per line.
 * The first line carries `message.role`; subsequent lines carry
 * `message.thinking` and/or `message.content` deltas. The final line has
 * `done: true` and includes `eval_count` (output tokens) and
 * `prompt_eval_count` (input tokens). Tool calls appear only in the final
 * line's `message.tool_calls[]` array.
 *
 * One instance covers exactly one invocation. Create a fresh CueMapper
 * per {@see ChatProvider::perform()} call.
 *
 * Final — sealed stateful mapper; the sequence counter and start-state
 * flag are correctness properties that subclasses cannot safely alter.
 */
final class CueMapper implements NdjsonStreamingCueMapper
{
    private int $sequence = 0;

    private bool $started = false;

    /**
     * Set to true after TokenStop + FinalUsage + Completed are emitted — either
     * via the wire-native done:true path or the defensive complete() path.
     * Prevents double emission regardless of which path fires first.
     */
    private bool $completed = false;

    /** Input token count from the done line's prompt_eval_count field. */
    private int $inputTokens = 0;

    /** Output token count from the done line's eval_count field. */
    private int $outputTokens = 0;

    public function __construct(
        private(set) Invocation $invocation,
    ) {
    }

    /**
     * Translate one Ollama NDJSON line (decoded array) into zero or more Cues.
     *
     * @param array<string, mixed> $line Decoded NDJSON line from the Ollama wire format.
     * @return \Generator<int, Cue>
     */
    public function translate(array $line): \Generator
    {
        $now = new \DateTimeImmutable();

        // Ollama error response — a standalone line with only an `error` key.
        if (isset($line['error']) && is_string($line['error'])) {
            $this->completed = true;

            yield new Failed(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                reason: $line['error'],
                errorClass: null,
            );

            return;
        }

        $message = is_array($line['message'] ?? null) ? $line['message'] : [];
        $role = isset($message['role']) ? (string) $message['role'] : '';
        $content = isset($message['content']) ? (string) $message['content'] : '';
        $thinking = isset($message['thinking']) ? (string) $message['thinking'] : '';
        $done = (bool) ($line['done'] ?? false);

        // First line — role present signals stream start.
        if (!$this->started && $role !== '') {
            $this->started = true;
            $model = (string) ($line['model'] ?? '');

            yield new Resolved(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                provider: 'ollama',
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

        if ($thinking !== '') {
            yield new TokenDelta(
                id: (string) Id::ulid(),
                sequence: $this->sequence++,
                activityId: $this->invocation->activityId,
                invocationId: $this->invocation->id,
                agentId: $this->invocation->agentId,
                at: $now,
                text: $thinking,
                channel: Channel::Thinking,
            );
        }

        if ($content !== '') {
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

        // Tool calls — only present in the final done line.
        if ($done) {
            $toolCalls = isset($message['tool_calls']) && is_array($message['tool_calls'])
                ? $message['tool_calls']
                : [];

            // Normalize to well-typed list, dropping non-array entries.
            /** @var list<array<string, mixed>> $validToolCalls */
            $validToolCalls = [];
            foreach ($toolCalls as $tc) {
                if (!is_array($tc)) {
                    continue;
                }

                /** @var array<string, mixed> $tc */
                $validToolCalls[] = $tc;
                yield from $this->onToolCall($tc, $now);
            }

            yield from $this->onDone($line, $validToolCalls, $now);
        }
    }

    /**
     * Guarded post-loop terminator. Called by the provider after the NDJSON
     * reader flush. Emits TokenStop + FinalUsage + Completed only when the
     * stream actually started ($started === true) and completion has not already
     * been emitted ($completed === false).
     *
     * Handles transport truncation and mid-stream cancellation where the wire-
     * native done:true line never arrives.
     *
     * @return \Generator<int, Cue>
     */
    public function complete(): \Generator
    {
        if (!$this->started || $this->completed) {
            return;
        }

        yield from $this->emitTerminal([], new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $tc
     * @return \Generator<int, Cue>
     */
    private function onToolCall(array $tc, \DateTimeImmutable $now): \Generator
    {
        $function = is_array($tc['function'] ?? null) ? $tc['function'] : [];
        $name = (string) ($function['name'] ?? 'unknown');
        $effectId = 'tc_' . (string) Id::ulid();

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
            arguments: is_array($function['arguments'] ?? null) ? $function['arguments'] : [],
            requiresApproval: false,
        );
    }

    /**
     * @param array<string, mixed> $line
     * @param list<array<string, mixed>> $toolCalls
     * @return \Generator<int, Cue>
     */
    private function onDone(array $line, array $toolCalls, \DateTimeImmutable $now): \Generator
    {
        if ($this->completed) {
            return;
        }

        $this->inputTokens = (int) ($line['prompt_eval_count'] ?? 0);
        $this->outputTokens = (int) ($line['eval_count'] ?? 0);

        yield from $this->emitTerminal($toolCalls, $now);
    }

    /**
     * Emits TokenStop + FinalUsage + Completed and marks $completed = true.
     * Must only be called after verifying !$this->completed.
     *
     * @param list<array<string, mixed>> $toolCalls present tool calls (empty on defensive path)
     * @return \Generator<int, Cue>
     */
    private function emitTerminal(array $toolCalls, \DateTimeImmutable $now): \Generator
    {
        $this->completed = true;

        // When tool calls were present in the done line, the model stopped to
        // invoke tools — not because it finished a turn naturally.
        $stopReason = $toolCalls !== [] ? StopReason::ToolUse : StopReason::EndOfTurn;

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
}
