<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

use DateTimeImmutable;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Cancelled as ActivityCancelled;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
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
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Runtime\Error as RuntimeError;
use Phalanx\Panoply\Cue\Runtime\Notice as RuntimeNotice;
use Phalanx\Panoply\Cue\Runtime\Warning as RuntimeWarning;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;

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

    private static function projectionForCue(Cue $cue): ConversationTurnEventProjection
    {
        return match (true) {
            $cue instanceof TokenDelta => self::projectionForToken($cue->channel),
            $cue instanceof TokenStop => new ConversationTurnEventProjection(
                kind: ConversationTurnEventKind::TokenStop,
                severity: self::severityForStopReason($cue->reason->value),
                label: 'stop',
                summary: $cue->reason->value,
                stopReason: $cue->reason->value,
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
                arguments: $cue->arguments,
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
                severity: self::severityForStopReason($cue->stopReason->value),
                label: 'invocation',
                summary: $cue->stopReason->value,
                stopReason: $cue->stopReason->value,
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

    private static function severityForStopReason(string $reason): ConversationTurnEventSeverity
    {
        return match ($reason) {
            'error' => ConversationTurnEventSeverity::Error,
            'max-tokens' => ConversationTurnEventSeverity::Warning,
            'tool-use' => ConversationTurnEventSeverity::Info,
            'cancelled' => ConversationTurnEventSeverity::Warning,
            default => ConversationTurnEventSeverity::Muted,
        };
    }
}
