<?php

declare(strict_types=1);

namespace Phalanx\Agora\Theatron;

use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionSet;
use Phalanx\Agora\Harness\Replay\ReplaySession;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputModeSlice;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Screen\ChatScreen;
use Phalanx\Theatron\Template\Slice\ActivitySlice;
use Phalanx\Theatron\Template\Slice\ActivityStatus;
use Phalanx\Theatron\Template\Slice\ConversationSlice;
use Phalanx\Theatron\Template\Slice\ConversationTurn;
use Phalanx\Theatron\Template\Slice\ConversationTurnEvent;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventKind;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventProjection;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventSeverity;
use Phalanx\Theatron\Template\Slice\ConversationTurnStatus;
use Phalanx\Theatron\Template\Slice\EffectLogEntry;
use Phalanx\Theatron\Template\Slice\EffectLogSlice;
use Phalanx\Theatron\Template\Slice\EffectStatus;
use Phalanx\Theatron\Template\Slice\WorkspaceViewSlice;

final class TheatronReplayHydrator
{
    public function hydrate(
        AppStore $store,
        ReplaySession $session,
    ): void {
        $projections = self::project($session);

        $store->conversation = ConversationSlice::fromTurns(
            turns: self::turns($session->events),
            isStreaming: self::isStreaming($projections),
        );
        $store->activity = self::activity($projections);
        $store->effects = new EffectLogSlice(self::effectEntries($session->events));
        $store->workspaceView = self::workspace($projections);
    }

    private static function tokenProjection(
        HarnessEvent $event,
    ): ConversationTurnEventProjection {
        return match (self::channel($event)) {
            Channel::Reasoning => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ReasoningDelta,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'reasoning',
            ),
            Channel::Thinking => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ThinkingDelta,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'thinking',
            ),
            default => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::MessageDelta,
                severity: ConversationTurnEventSeverity::Info,
                label: 'answer',
            ),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function runtimeProjection(
        array $payload,
        ConversationTurnEventKind $kind,
        ConversationTurnEventSeverity $severity,
        string $label,
    ): ConversationTurnEventProjection {
        return new ConversationTurnEventProjection(
            kind: $kind,
            severity: $severity,
            label: $label,
            summary: self::stringValue($payload['message'] ?? null),
            reason: self::stringValue($payload['code'] ?? null),
            errorClass: self::stringValue($payload['error_class'] ?? null),
        );
    }

    private static function channel(
        HarnessEvent $event,
    ): ?Channel {
        if ($event->channel !== null) {
            return Channel::tryFrom($event->channel);
        }

        return Channel::tryFrom(self::stringValue(self::payload($event)['channel'] ?? null) ?? '');
    }

    private static function tokenText(
        HarnessEvent $event,
    ): string {
        if ($event->cueType !== 'cue.output.token_delta') {
            return '';
        }

        return self::stringValue(self::payload($event)['text'] ?? null) ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(
        HarnessEvent $event,
    ): array {
        $payload = $event->payload['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }

    private static function userText(
        HarnessEvent $event,
    ): string {
        $userText = $event->payload['user_text']
            ?? $event->payload['content']
            ?? $event->payload['prompt']
            ?? null;

        return is_string($userText) ? $userText : '';
    }

    private static function stopReason(
        mixed $value,
    ): StopReason {
        return StopReason::tryFrom(is_string($value) ? $value : '') ?? StopReason::EndOfTurn;
    }

    private static function turnStatusForStopReason(
        StopReason $reason,
    ): ConversationTurnStatus {
        return match ($reason) {
            StopReason::Error => ConversationTurnStatus::Failed,
            StopReason::Cancelled => ConversationTurnStatus::Cancelled,
            StopReason::ToolUse => ConversationTurnStatus::Running,
            default => ConversationTurnStatus::Completed,
        };
    }

    private static function severityForStopReason(
        StopReason $reason,
    ): ConversationTurnEventSeverity {
        return match ($reason) {
            StopReason::Error => ConversationTurnEventSeverity::Error,
            StopReason::MaxTokens => ConversationTurnEventSeverity::Warning,
            StopReason::ToolUse => ConversationTurnEventSeverity::Info,
            StopReason::Cancelled => ConversationTurnEventSeverity::Warning,
            default => ConversationTurnEventSeverity::Muted,
        };
    }

    private static function severityForEffectOutcome(
        string $outcome,
    ): ConversationTurnEventSeverity {
        return match (strtolower(trim($outcome))) {
            'ok',
            'success',
            'succeeded',
            'complete',
            'completed',
            'exit_0' => ConversationTurnEventSeverity::Success,
            'denied',
            'error',
            'failed',
            'failure' => ConversationTurnEventSeverity::Error,
            'paused',
            'suspended',
            'waiting_for_approval',
            'waiting-for-approval' => ConversationTurnEventSeverity::Warning,
            default => ConversationTurnEventSeverity::Info,
        };
    }

    private static function labelForResolution(
        ?Resolution $resolution,
    ): string {
        return match ($resolution) {
            Resolution::BuiltIn => 'built-in',
            Resolution::LocalTool => 'local tool',
            Resolution::McpTool => 'mcp tool',
            Resolution::SubAgent => 'sub-agent',
            null => 'effect',
        };
    }

    private static function durationSummary(
        int $durationMs,
        ?string $resultDigest,
    ): string {
        $summary = $durationMs . 'ms';

        if ($resultDigest !== null && $resultDigest !== '') {
            $summary .= ' · ' . $resultDigest;
        }

        return $summary;
    }

    private static function pathSummary(
        ?string $path,
        string $body,
    ): string {
        if ($path === null || $path === '') {
            return $body;
        }

        return $path . ' · ' . $body;
    }

    /** @param array<string, mixed> $payload */
    private static function providerSummary(
        array $payload,
    ): string {
        $provider = self::stringValue($payload['provider'] ?? null) ?? 'provider';
        $model = self::stringValue($payload['model'] ?? null);
        $reason = self::stringValue($payload['reason_code'] ?? null);

        return trim(implode(' ', array_filter([$provider, $model]))) . ($reason === null ? '' : ' · ' . $reason);
    }

    /** @param array<string, mixed> $payload */
    private static function retrySummary(
        array $payload,
    ): string {
        $provider = self::stringValue($payload['provider'] ?? null) ?? 'provider';
        $attempt = self::intValue($payload['attempt'] ?? null);
        $maxAttempts = self::intValue($payload['max_attempts'] ?? null);
        $backoffMs = self::nullableInt($payload['backoff_ms'] ?? null);
        $summary = "{$provider} attempt {$attempt}/{$maxAttempts}";

        if ($backoffMs !== null) {
            $summary .= " · backoff {$backoffMs}ms";
        }

        return $summary;
    }

    private static function intValue(
        mixed $value,
    ): int {
        return is_int($value) ? $value : 0;
    }

    private static function nullableInt(
        mixed $value,
    ): ?int {
        return is_int($value) ? $value : null;
    }

    private static function floatValue(
        mixed $value,
    ): ?float {
        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    private static function stringValue(
        mixed $value,
    ): ?string {
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapValue(
        mixed $value,
    ): array {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @return list<string> */
    private static function stringList(
        mixed $value,
    ): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn(mixed $item): bool => is_string($item)));
    }

    private static function project(
        ReplaySession $session,
    ): ProjectionSet {
        $projections = ProjectionSet::empty($session->sessionId);

        foreach ($session->events as $event) {
            $projections = $projections->apply($event);
        }

        return $projections;
    }

    /**
     * @param list<HarnessEvent> $events
     * @return list<ConversationTurn>
     */
    private static function turns(
        array $events,
    ): array {
        $turns = [];
        $order = [];

        foreach ($events as $event) {
            if ($event->turnId === null) {
                continue;
            }

            if (!isset($turns[$event->turnId])) {
                $order[] = $event->turnId;
                $turns[$event->turnId] = new ConversationTurn(
                    id: $event->turnId,
                    userText: self::userText($event),
                    at: $event->occurredAt,
                );
            }

            if ($turns[$event->turnId]->userText === '' && self::userText($event) !== '') {
                $turns[$event->turnId] = new ConversationTurn(
                    id: $event->turnId,
                    userText: self::userText($event),
                    at: $turns[$event->turnId]->at,
                    status: $turns[$event->turnId]->status,
                    events: $turns[$event->turnId]->events,
                );
            }

            $projection = self::projection($event);
            if ($projection !== null) {
                $turns[$event->turnId] = $turns[$event->turnId]->withEvent(new ConversationTurnEvent(
                    id: $event->id,
                    at: $event->occurredAt,
                    projection: $projection,
                    channel: self::channel($event),
                    text: self::tokenText($event),
                ));
            }

            $status = self::turnStatus($event);
            if ($status !== null) {
                $turns[$event->turnId] = $turns[$event->turnId]->withStatus($status);
            }
        }

        return array_values(array_map(
            static fn(string $turnId): ConversationTurn => $turns[$turnId],
            $order,
        ));
    }

    private static function isStreaming(
        ProjectionSet $projections,
    ): bool {
        return $projections->activity->status->value === 'streaming';
    }

    private static function activity(
        ProjectionSet $projections,
    ): ActivitySlice {
        $usage = $projections->activity->usage;

        return new ActivitySlice(
            status: match ($projections->activity->status->value) {
                'streaming' => ActivityStatus::Running,
                'waiting_approval' => ActivityStatus::AwaitingApproval,
                'failed' => ActivityStatus::Failed,
                'cancelled' => ActivityStatus::Cancelled,
                default => ActivityStatus::Completed,
            },
            inputTokens: self::intValue($usage['input_tokens'] ?? null),
            outputTokens: self::intValue($usage['output_tokens'] ?? null),
            totalTokens: self::intValue($usage['total_tokens'] ?? null),
        );
    }

    private static function workspace(
        ProjectionSet $projections,
    ): WorkspaceViewSlice {
        $restore = $projections->workspace->restore;
        $scrollOffset = $restore['scroll_offset'] ?? 0;
        $selectedTurnId = $restore['selected_turn_id'] ?? null;
        $expandedBlock = $restore['expanded_block'] ?? null;
        $inputMode = InputMode::tryFrom(is_string($restore['input_mode'] ?? null) ? $restore['input_mode'] : '');

        $inputModes = [];
        if ($inputMode !== null) {
            $inputModes[ChatScreen::class] = new InputModeSlice($inputMode, focusTarget: null);
        }

        return new WorkspaceViewSlice(
            chatScrollOffset: is_int($scrollOffset) ? max(0, $scrollOffset) : 0,
            expandedTurnId: is_string($expandedBlock) ? $expandedBlock : null,
            selectedTurnId: is_string($selectedTurnId) ? $selectedTurnId : null,
            inputModes: $inputModes,
        );
    }

    /**
     * @param list<HarnessEvent> $events
     * @return list<EffectLogEntry>
     */
    private static function effectEntries(
        array $events,
    ): array {
        $entries = [];

        foreach ($events as $event) {
            if ($event->cueType === 'athena.effect_log') {
                $entries[$event->id] = self::athenaEffectEntry($event);

                continue;
            }

            if (!str_starts_with($event->cueType, 'cue.effect.')) {
                continue;
            }

            $payload = self::payload($event);
            $effectId = self::stringValue($payload['effect_id'] ?? null);
            if ($effectId === null) {
                continue;
            }

            $entryKey = self::effectEntryKey($event, $effectId);
            $entries[$entryKey] = self::effectEntry($event, $entries[$entryKey] ?? null);
        }

        return array_values($entries);
    }

    private static function effectEntryKey(
        HarnessEvent $event,
        string $effectId,
    ): string {
        $activityId = self::stringValue($event->payload['activity_id'] ?? null);

        return ($event->turnId ?? $activityId ?? 'session') . ':' . $effectId;
    }

    private static function projection(
        HarnessEvent $event,
    ): ?ConversationTurnEventProjection {
        $payload = self::payload($event);

        return match ($event->cueType) {
            'cue.output.token_delta' => self::tokenProjection($event),
            'cue.output.token_stop' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::TokenStop,
                severity: self::severityForStopReason(self::stopReason($payload['reason'] ?? null)),
                label: 'stop',
                summary: self::stopReason($payload['reason'] ?? null)->value,
                stopReason: self::stopReason($payload['reason'] ?? null),
            ),
            'cue.output.structured_delta' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::StructuredDelta,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'structured',
                summary: self::pathSummary(
                    self::stringValue($payload['path'] ?? null),
                    self::stringValue($payload['json_delta'] ?? null) ?? '',
                ),
                structuredDelta: self::stringValue($payload['json_delta'] ?? null),
                structuredPath: self::stringValue($payload['path'] ?? null),
            ),
            'cue.effect.requested' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectRequested,
                severity: ($payload['requires_approval'] ?? false) === true
                    ? ConversationTurnEventSeverity::Warning
                    : ConversationTurnEventSeverity::Info,
                label: ($payload['requires_approval'] ?? false) === true ? 'approval' : 'effect',
                summary: self::stringValue($payload['summary'] ?? null),
                effectId: self::stringValue($payload['effect_id'] ?? null),
                effectKind: self::stringValue($payload['kind'] ?? null),
                arguments: self::mapValue($payload['arguments'] ?? null),
                requiresApproval: ($payload['requires_approval'] ?? false) === true,
            ),
            'cue.effect.arguments_delta' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectArgumentsDelta,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'arguments',
                summary: self::stringValue($payload['json_delta'] ?? null),
                effectId: self::stringValue($payload['effect_id'] ?? null),
                argumentsDelta: self::stringValue($payload['json_delta'] ?? null),
            ),
            'cue.effect.authorized' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectAuthorized,
                severity: ConversationTurnEventSeverity::Success,
                label: 'authorized',
                effectId: self::stringValue($payload['effect_id'] ?? null),
                grantId: self::stringValue($payload['grant_id'] ?? null),
            ),
            'cue.effect.paused' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectPaused,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'paused',
                summary: self::stringValue($payload['reason'] ?? null),
                effectId: self::stringValue($payload['effect_id'] ?? null),
                reason: self::stringValue($payload['reason'] ?? null),
            ),
            'cue.effect.denied' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectDenied,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'denied',
                summary: implode(', ', self::stringList($payload['reason_codes'] ?? null)),
                effectId: self::stringValue($payload['effect_id'] ?? null),
                reasonCodes: self::stringList($payload['reason_codes'] ?? null),
            ),
            'cue.effect.executed' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectExecuted,
                severity: ConversationTurnEventSeverity::Success,
                label: 'executed',
                summary: self::durationSummary(
                    self::intValue($payload['duration_ms'] ?? null),
                    self::stringValue($payload['result_digest'] ?? null),
                ),
                effectId: self::stringValue($payload['effect_id'] ?? null),
                durationMs: self::intValue($payload['duration_ms'] ?? null),
                resultDigest: self::stringValue($payload['result_digest'] ?? null),
            ),
            'cue.effect.failed' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectFailed,
                severity: ConversationTurnEventSeverity::Error,
                label: 'failed',
                summary: self::stringValue($payload['reason'] ?? null),
                effectId: self::stringValue($payload['effect_id'] ?? null),
                reason: self::stringValue($payload['reason'] ?? null),
                errorClass: self::stringValue($payload['error_class'] ?? null),
            ),
            'cue.invocation.started' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::InvocationStarted,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'invocation',
                summary: 'started',
            ),
            'cue.invocation.completed' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::InvocationCompleted,
                severity: self::severityForStopReason(self::stopReason($payload['stop_reason'] ?? null)),
                label: 'invocation',
                summary: self::stopReason($payload['stop_reason'] ?? null)->value,
                stopReason: self::stopReason($payload['stop_reason'] ?? null),
            ),
            'cue.invocation.failed' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::InvocationFailed,
                severity: ConversationTurnEventSeverity::Error,
                label: 'invocation failed',
                summary: self::stringValue($payload['reason'] ?? null),
                reason: self::stringValue($payload['reason'] ?? null),
                errorClass: self::stringValue($payload['error_class'] ?? null),
            ),
            'cue.invocation.cancelled' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::InvocationCancelled,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'invocation cancelled',
                summary: self::stringValue($payload['reason'] ?? null),
                reason: self::stringValue($payload['reason'] ?? null),
            ),
            'cue.usage.delta', 'cue.usage.final' => self::usageProjection($event),
            'cue.activity.started' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ActivityStarted,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'activity',
                summary: 'started',
            ),
            'cue.activity.completed' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ActivityCompleted,
                severity: ConversationTurnEventSeverity::Success,
                label: 'activity',
                summary: 'completed',
            ),
            'cue.activity.failed' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ActivityFailed,
                severity: ConversationTurnEventSeverity::Error,
                label: 'activity failed',
                summary: self::stringValue($payload['reason'] ?? null),
                reason: self::stringValue($payload['reason'] ?? null),
                errorClass: self::stringValue($payload['error_class'] ?? null),
            ),
            'cue.activity.cancelled' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ActivityCancelled,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'activity cancelled',
                summary: self::stringValue($payload['reason'] ?? null),
                reason: self::stringValue($payload['reason'] ?? null),
            ),
            'cue.provider.resolved' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ProviderResolved,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'provider',
                summary: self::providerSummary($payload),
                provider: self::stringValue($payload['provider'] ?? null),
                model: self::stringValue($payload['model'] ?? null),
                reason: self::stringValue($payload['reason_code'] ?? null),
            ),
            'cue.provider.retrying' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ProviderRetrying,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'retrying',
                summary: self::retrySummary($payload),
                provider: self::stringValue($payload['provider'] ?? null),
                attempt: self::intValue($payload['attempt'] ?? null),
                maxAttempts: self::intValue($payload['max_attempts'] ?? null),
                backoffMs: self::nullableInt($payload['backoff_ms'] ?? null),
            ),
            'cue.provider.rate_limited' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ProviderRateLimited,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'rate limited',
                summary: self::providerSummary($payload),
                provider: self::stringValue($payload['provider'] ?? null),
                model: self::stringValue($payload['model'] ?? null),
                retryAfterSeconds: self::nullableInt($payload['retry_after_seconds'] ?? null),
            ),
            'cue.runtime.client_connected' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::RuntimeClientConnected,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'client',
                summary: self::stringValue($payload['client_kind'] ?? null),
                clientId: self::stringValue($payload['client_id'] ?? null),
                clientKind: self::stringValue($payload['client_kind'] ?? null),
            ),
            'cue.runtime.client_disconnected' => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::RuntimeClientDisconnected,
                severity: self::stringValue($payload['reason'] ?? null) === null
                    ? ConversationTurnEventSeverity::Muted
                    : ConversationTurnEventSeverity::Warning,
                label: 'client disconnected',
                summary: self::stringValue($payload['reason'] ?? null),
                reason: self::stringValue($payload['reason'] ?? null),
                clientId: self::stringValue($payload['client_id'] ?? null),
            ),
            'cue.runtime.error' => self::runtimeProjection($payload, ConversationTurnEventKind::RuntimeError, ConversationTurnEventSeverity::Error, 'runtime error'),
            'cue.runtime.warning' => self::runtimeProjection($payload, ConversationTurnEventKind::RuntimeWarning, ConversationTurnEventSeverity::Warning, 'runtime warning'),
            'cue.runtime.notice' => self::runtimeProjection($payload, ConversationTurnEventKind::RuntimeNotice, ConversationTurnEventSeverity::Info, 'runtime notice'),
            'athena.effect_log' => self::athenaProjection($event),
            default => null,
        };
    }

    private static function usageProjection(
        HarnessEvent $event,
    ): ConversationTurnEventProjection {
        $payload = self::payload($event);
        $input = self::intValue($payload['input_tokens'] ?? null);
        $output = self::intValue($payload['output_tokens'] ?? null);

        return new ConversationTurnEventProjection(
            kind: $event->cueType === 'cue.usage.final'
                ? ConversationTurnEventKind::UsageFinal
                : ConversationTurnEventKind::UsageDelta,
            severity: ConversationTurnEventSeverity::Muted,
            label: 'usage',
            summary: "{$input} in · {$output} out · " . ($input + $output) . ' total',
            inputTokens: $input,
            outputTokens: $output,
            cacheReadTokens: self::intValue($payload['cache_read_tokens'] ?? null),
            cacheWriteTokens: self::intValue($payload['cache_write_tokens'] ?? null),
            costUsd: $event->cueType === 'cue.usage.final' ? self::floatValue($payload['cost_usd'] ?? null) : null,
        );
    }

    private static function athenaProjection(
        HarnessEvent $event,
    ): ConversationTurnEventProjection {
        $resolution = Resolution::tryFrom(self::stringValue($event->payload['resolution'] ?? null) ?? '');
        $outcome = self::stringValue($event->payload['outcome'] ?? null) ?? '';

        return new ConversationTurnEventProjection(
            kind: ConversationTurnEventKind::EffectLogged,
            severity: self::severityForEffectOutcome($outcome),
            label: self::labelForResolution($resolution),
            summary: $outcome,
            effectKind: self::stringValue($event->payload['kind'] ?? null),
            resolution: $resolution,
            toolName: self::stringValue($event->payload['tool_name'] ?? null),
            argsHash: self::stringValue($event->payload['args_hash'] ?? null),
            outcome: $outcome,
        );
    }

    private static function turnStatus(
        HarnessEvent $event,
    ): ?ConversationTurnStatus {
        $payload = self::payload($event);

        return match ($event->cueType) {
            'cue.effect.requested' => ($payload['requires_approval'] ?? false) === true
                ? ConversationTurnStatus::AwaitingApproval
                : null,
            'cue.effect.paused' => ConversationTurnStatus::AwaitingApproval,
            'cue.output.token_stop' => self::turnStatusForStopReason(self::stopReason($payload['reason'] ?? null)),
            'cue.invocation.completed' => self::turnStatusForStopReason(self::stopReason($payload['stop_reason'] ?? null)),
            'cue.invocation.failed', 'cue.activity.failed' => ConversationTurnStatus::Failed,
            'cue.invocation.cancelled', 'cue.activity.cancelled' => ConversationTurnStatus::Cancelled,
            'cue.activity.completed' => ConversationTurnStatus::Completed,
            'cue.effect.authorized', 'cue.effect.executed', 'cue.effect.denied', 'cue.effect.failed' => ConversationTurnStatus::Running,
            default => null,
        };
    }

    private static function effectEntry(
        HarnessEvent $event,
        ?EffectLogEntry $existing,
    ): EffectLogEntry {
        $payload = self::payload($event);
        $effectId = self::stringValue($payload['effect_id'] ?? null) ?? $event->id;
        $entry = $existing ?? new EffectLogEntry(
            effectId: $effectId,
            activityId: self::stringValue($event->payload['activity_id'] ?? null) ?? '',
            invocationId: self::stringValue($event->payload['invocation_id'] ?? null),
            agentId: self::stringValue($event->payload['agent_id'] ?? null),
            kind: self::stringValue($payload['kind'] ?? null) ?? 'effect',
            summary: self::stringValue($payload['summary'] ?? null) ?? $effectId,
            hazard: 'none',
            arguments: self::mapValue($payload['arguments'] ?? null),
        );

        $status = match ($event->cueType) {
            'cue.effect.paused' => EffectStatus::Paused,
            'cue.effect.authorized' => EffectStatus::Approved,
            'cue.effect.denied' => EffectStatus::Denied,
            'cue.effect.executed' => EffectStatus::Executed,
            'cue.effect.failed' => EffectStatus::Failed,
            default => EffectStatus::Requested,
        };

        if ($status === EffectStatus::Requested) {
            return $entry;
        }

        return $entry->withStatus(
            status: $status,
            reasonCodes: self::stringList($payload['reason_codes'] ?? null),
            grantId: self::stringValue($payload['grant_id'] ?? null),
            durationMs: self::nullableInt($payload['duration_ms'] ?? null),
            errorClass: self::stringValue($payload['error_class'] ?? null),
        );
    }

    private static function athenaEffectEntry(
        HarnessEvent $event,
    ): EffectLogEntry {
        $outcome = self::stringValue($event->payload['outcome'] ?? null) ?? '';

        return new EffectLogEntry(
            effectId: self::stringValue($event->payload['record_id'] ?? null) ?? $event->id,
            activityId: self::stringValue($event->payload['record_id'] ?? null) ?? $event->id,
            invocationId: self::stringValue($event->payload['invocation_id'] ?? null),
            agentId: null,
            kind: self::stringValue($event->payload['kind'] ?? null) ?? 'effect',
            summary: $outcome,
            hazard: 'none',
            status: match (self::severityForEffectOutcome($outcome)) {
                ConversationTurnEventSeverity::Success => EffectStatus::Executed,
                ConversationTurnEventSeverity::Error => EffectStatus::Failed,
                ConversationTurnEventSeverity::Warning => EffectStatus::Paused,
                default => EffectStatus::Requested,
            },
        );
    }
}
