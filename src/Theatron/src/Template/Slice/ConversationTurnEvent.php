<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

use DateTimeImmutable;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Cancelled as ActivityCancelled;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Artifact\Delta as ArtifactDelta;
use Phalanx\Panoply\Cue\Artifact\Drafting as ArtifactDrafting;
use Phalanx\Panoply\Cue\Artifact\Finalized as ArtifactFinalized;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta as EffectArgumentsDelta;
use Phalanx\Panoply\Cue\Effect\Authorized as EffectAuthorized;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
use Phalanx\Panoply\Cue\Effect\Paused as EffectPaused;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Invocation\Cancelled as InvocationCancelled;
use Phalanx\Panoply\Cue\Invocation\Completed as InvocationCompleted;
use Phalanx\Panoply\Cue\Invocation\Failed as InvocationFailed;
use Phalanx\Panoply\Cue\Invocation\Started as InvocationStarted;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\StructuredDelta;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Provider\RateLimited as ProviderRateLimited;
use Phalanx\Panoply\Cue\Provider\Resolved as ProviderResolved;
use Phalanx\Panoply\Cue\Provider\Retrying as ProviderRetrying;
use Phalanx\Panoply\Cue\Runtime\ClientConnected as RuntimeClientConnected;
use Phalanx\Panoply\Cue\Runtime\ClientDisconnected as RuntimeClientDisconnected;
use Phalanx\Panoply\Cue\Runtime\Error as RuntimeError;
use Phalanx\Panoply\Cue\Runtime\Notice as RuntimeNotice;
use Phalanx\Panoply\Cue\Runtime\Warning as RuntimeWarning;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Grant;

class ConversationTurnEvent
{
    public function __construct(
        private(set) string $id,
        private(set) DateTimeImmutable $at,
        private(set) ConversationTurnEventProjection $projection,
        private(set) ?Cue $cue = null,
        private(set) ?Channel $channel = null,
        private(set) string $text = '',
    ) {
    }

    public static function fromCue(Cue $cue): self
    {
        $channel = match (true) {
            $cue instanceof TokenDelta => $cue->channel,
            $cue instanceof TokenStop => $cue->channel,
            default => null,
        };

        return new self(
            id: $cue->id,
            at: $cue->at,
            projection: self::projectionForCue($cue),
            cue: $cue,
            channel: $channel,
            text: $cue instanceof TokenDelta ? $cue->text : '',
        );
    }

    public static function token(string $id, DateTimeImmutable $at, string $text, Channel $channel): self
    {
        return new self(
            id: $id,
            at: $at,
            projection: self::projectionForToken($channel),
            channel: $channel,
            text: $text,
        );
    }

    public static function fromEffectLog(EffectLogRecord $record): self
    {
        return new self(
            id: $record->id,
            at: $record->at,
            projection: new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectLogged,
                severity: self::severityForEffectOutcome($record->outcome),
                label: self::labelForResolution($record->resolution),
                summary: $record->outcome,
                effectKind: $record->kind,
                resolution: $record->resolution,
                toolName: $record->toolName,
                argsHash: $record->argsHash,
                outcome: $record->outcome,
            ),
        );
    }

    public static function fromGrant(Grant $grant, ?DateTimeImmutable $at = null): self
    {
        return new self(
            id: $grant->id,
            at: $at ?? new DateTimeImmutable(),
            projection: new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::GrantAvailable,
                severity: ConversationTurnEventSeverity::Info,
                label: 'grant',
                summary: $grant->scope . ' · ' . $grant->hazardCeiling->value,
                grantId: $grant->id,
                subject: $grant->subject,
                scope: $grant->scope,
                hazardCeiling: $grant->hazardCeiling->value,
                expiresAt: $grant->expiresAt,
                allowedEffects: $grant->allowedEffects,
                conditions: $grant->conditions,
            ),
        );
    }

    private static function projectionForCue(Cue $cue): ConversationTurnEventProjection
    {
        return match (true) {
            $cue instanceof TokenDelta => self::projectionForToken($cue->channel),
            $cue instanceof TokenStop => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::TokenStop,
                severity: self::severityForStopReason($cue->reason),
                label: 'stop',
                summary: $cue->reason->value,
                stopReason: $cue->reason,
            ),
            $cue instanceof StructuredDelta => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::StructuredDelta,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'structured',
                summary: self::pathSummary($cue->path, $cue->jsonDelta),
                structuredDelta: $cue->jsonDelta,
                structuredPath: $cue->path,
            ),
            $cue instanceof EffectRequested => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectRequested,
                severity: $cue->requiresApproval
                    ? ConversationTurnEventSeverity::Warning
                    : ConversationTurnEventSeverity::Info,
                label: $cue->requiresApproval ? 'approval' : 'effect',
                summary: $cue->summary,
                effectId: $cue->effectId,
                effectKind: $cue->kind->value,
                toolName: $cue->effectId,
                arguments: $cue->arguments,
                requiresApproval: $cue->requiresApproval,
            ),
            $cue instanceof EffectArgumentsDelta => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectArgumentsDelta,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'arguments',
                summary: $cue->jsonDelta,
                effectId: $cue->effectId,
                argumentsDelta: $cue->jsonDelta,
            ),
            $cue instanceof EffectAuthorized => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectAuthorized,
                severity: ConversationTurnEventSeverity::Success,
                label: 'authorized',
                effectId: $cue->effectId,
                grantId: $cue->grantId,
            ),
            $cue instanceof EffectPaused => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectPaused,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'paused',
                summary: $cue->reason,
                effectId: $cue->effectId,
                reason: $cue->reason,
            ),
            $cue instanceof EffectDenied => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectDenied,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'denied',
                summary: implode(', ', $cue->reasonCodes),
                effectId: $cue->effectId,
                reasonCodes: $cue->reasonCodes,
            ),
            $cue instanceof EffectExecuted => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectExecuted,
                severity: ConversationTurnEventSeverity::Success,
                label: 'executed',
                summary: self::durationSummary($cue->durationMs, $cue->resultDigest),
                effectId: $cue->effectId,
                durationMs: $cue->durationMs,
                resultDigest: $cue->resultDigest,
            ),
            $cue instanceof EffectFailed => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::EffectFailed,
                severity: ConversationTurnEventSeverity::Error,
                label: 'failed',
                summary: $cue->reason,
                effectId: $cue->effectId,
                reason: $cue->reason,
                errorClass: $cue->errorClass,
            ),
            $cue instanceof InvocationStarted => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::InvocationStarted,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'invocation',
                summary: 'started',
            ),
            $cue instanceof InvocationCompleted => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::InvocationCompleted,
                severity: self::severityForStopReason($cue->stopReason),
                label: 'invocation',
                summary: $cue->stopReason->value,
                stopReason: $cue->stopReason,
            ),
            $cue instanceof InvocationFailed => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::InvocationFailed,
                severity: ConversationTurnEventSeverity::Error,
                label: 'invocation failed',
                summary: $cue->reason,
                reason: $cue->reason,
                errorClass: $cue->errorClass,
            ),
            $cue instanceof InvocationCancelled => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::InvocationCancelled,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'invocation cancelled',
                summary: $cue->reason,
                reason: $cue->reason,
            ),
            $cue instanceof UsageDelta => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::UsageDelta,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'usage',
                summary: self::usageSummary($cue->inputTokens, $cue->outputTokens),
                inputTokens: $cue->inputTokens,
                outputTokens: $cue->outputTokens,
                cacheReadTokens: $cue->cacheReadTokens,
                cacheWriteTokens: $cue->cacheWriteTokens,
            ),
            $cue instanceof FinalUsage => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::UsageFinal,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'usage',
                summary: self::usageSummary($cue->inputTokens, $cue->outputTokens),
                inputTokens: $cue->inputTokens,
                outputTokens: $cue->outputTokens,
                cacheReadTokens: $cue->cacheReadTokens,
                cacheWriteTokens: $cue->cacheWriteTokens,
                costUsd: $cue->costUsd,
            ),
            $cue instanceof ActivityStarted => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ActivityStarted,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'activity',
                summary: 'started',
            ),
            $cue instanceof ActivityCompleted => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ActivityCompleted,
                severity: ConversationTurnEventSeverity::Success,
                label: 'activity',
                summary: 'completed',
            ),
            $cue instanceof ActivityFailed => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ActivityFailed,
                severity: ConversationTurnEventSeverity::Error,
                label: 'activity failed',
                summary: $cue->reason,
                reason: $cue->reason,
                errorClass: $cue->errorClass,
            ),
            $cue instanceof ActivityCancelled => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ActivityCancelled,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'activity cancelled',
                summary: $cue->reason,
                reason: $cue->reason,
            ),
            $cue instanceof ArtifactDrafting => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ArtifactDrafting,
                severity: ConversationTurnEventSeverity::Info,
                label: 'artifact',
                summary: $cue->title ?? $cue->kind->value,
                artifactId: $cue->artifactId,
                artifactKind: $cue->kind->value,
            ),
            $cue instanceof ArtifactDelta => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ArtifactDelta,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'artifact delta',
                summary: self::pathSummary($cue->path, $cue->contentDelta),
                artifactId: $cue->artifactId,
                artifactPath: $cue->path,
            ),
            $cue instanceof ArtifactFinalized => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ArtifactFinalized,
                severity: ConversationTurnEventSeverity::Success,
                label: 'artifact',
                summary: 'finalized',
                artifactId: $cue->artifactId,
                contentHash: $cue->contentHash,
            ),
            $cue instanceof ProviderResolved => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ProviderResolved,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'provider',
                summary: self::providerModelSummary($cue->provider, $cue->model, $cue->reasonCode),
                provider: $cue->provider,
                model: $cue->model,
                reason: $cue->reasonCode,
            ),
            $cue instanceof ProviderRetrying => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ProviderRetrying,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'retrying',
                summary: self::retrySummary($cue->provider, $cue->attempt, $cue->maxAttempts, $cue->backoffMs),
                provider: $cue->provider,
                attempt: $cue->attempt,
                maxAttempts: $cue->maxAttempts,
                backoffMs: $cue->backoffMs,
            ),
            $cue instanceof ProviderRateLimited => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::ProviderRateLimited,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'rate limited',
                summary: self::rateLimitSummary($cue->provider, $cue->model, $cue->retryAfterSeconds),
                provider: $cue->provider,
                model: $cue->model,
                retryAfterSeconds: $cue->retryAfterSeconds,
            ),
            $cue instanceof RuntimeClientConnected => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::RuntimeClientConnected,
                severity: ConversationTurnEventSeverity::Muted,
                label: 'client',
                summary: $cue->clientKind,
                clientId: $cue->clientId,
                clientKind: $cue->clientKind,
            ),
            $cue instanceof RuntimeClientDisconnected => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::RuntimeClientDisconnected,
                severity: $cue->reason === null
                    ? ConversationTurnEventSeverity::Muted
                    : ConversationTurnEventSeverity::Warning,
                label: 'client disconnected',
                summary: $cue->reason,
                reason: $cue->reason,
                clientId: $cue->clientId,
            ),
            $cue instanceof RuntimeError => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::RuntimeError,
                severity: ConversationTurnEventSeverity::Error,
                label: 'runtime error',
                summary: $cue->message,
                reason: $cue->code,
                errorClass: $cue->errorClass,
            ),
            $cue instanceof RuntimeWarning => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::RuntimeWarning,
                severity: ConversationTurnEventSeverity::Warning,
                label: 'runtime warning',
                summary: $cue->message,
                reason: $cue->code,
            ),
            $cue instanceof RuntimeNotice => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::RuntimeNotice,
                severity: ConversationTurnEventSeverity::Info,
                label: 'runtime notice',
                summary: $cue->message,
                reason: $cue->code,
            ),
            default => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::RuntimeNotice,
                severity: ConversationTurnEventSeverity::Muted,
                label: $cue->type,
            ),
        };
    }

    private static function projectionForToken(Channel $channel): ConversationTurnEventProjection
    {
        return match ($channel) {
            Channel::Message => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::MessageDelta,
                severity: ConversationTurnEventSeverity::Info,
                label: 'answer',
            ),
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
        };
    }

    private static function durationSummary(int $durationMs, ?string $resultDigest): string
    {
        $summary = $durationMs . 'ms';

        if ($resultDigest !== null && $resultDigest !== '') {
            $summary .= ' · ' . $resultDigest;
        }

        return $summary;
    }

    private static function usageSummary(int $inputTokens, int $outputTokens): string
    {
        return $inputTokens . ' in · ' . $outputTokens . ' out · ' . ($inputTokens + $outputTokens) . ' total';
    }

    private static function severityForStopReason(StopReason $reason): ConversationTurnEventSeverity
    {
        return match ($reason) {
            StopReason::Error => ConversationTurnEventSeverity::Error,
            StopReason::MaxTokens => ConversationTurnEventSeverity::Warning,
            StopReason::ToolUse => ConversationTurnEventSeverity::Info,
            StopReason::Cancelled => ConversationTurnEventSeverity::Warning,
            default => ConversationTurnEventSeverity::Muted,
        };
    }

    private static function severityForEffectOutcome(string $outcome): ConversationTurnEventSeverity
    {
        $value = strtolower($outcome);

        if (str_contains($value, 'denied') || str_contains($value, 'fail') || str_contains($value, 'error')) {
            return ConversationTurnEventSeverity::Error;
        }

        if (str_contains($value, 'paused') || str_contains($value, 'suspend')) {
            return ConversationTurnEventSeverity::Warning;
        }

        return ConversationTurnEventSeverity::Success;
    }

    private static function labelForResolution(Resolution $resolution): string
    {
        return match ($resolution) {
            Resolution::BuiltIn => 'built-in',
            Resolution::LocalTool => 'local tool',
            Resolution::McpTool => 'mcp tool',
            Resolution::SubAgent => 'sub-agent',
        };
    }

    private static function pathSummary(?string $path, string $body): string
    {
        if ($path === null || $path === '') {
            return $body;
        }

        return $path . ' · ' . $body;
    }

    private static function providerModelSummary(string $provider, string $model, string $reason): string
    {
        return $provider . ' ' . $model . ' · ' . $reason;
    }

    private static function retrySummary(string $provider, int $attempt, int $maxAttempts, ?int $backoffMs): string
    {
        $summary = $provider . ' attempt ' . $attempt . '/' . $maxAttempts;

        if ($backoffMs !== null) {
            $summary .= ' · backoff ' . $backoffMs . 'ms';
        }

        return $summary;
    }

    private static function rateLimitSummary(string $provider, string $model, ?int $retryAfterSeconds): string
    {
        $summary = $provider . ' ' . $model;

        if ($retryAfterSeconds !== null) {
            $summary .= ' · retry after ' . $retryAfterSeconds . 's';
        }

        return $summary;
    }
}
