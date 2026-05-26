<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Render;

use Phalanx\Harness\Ui\Slices\ConversationTurnEvent;
use Phalanx\Harness\Ui\Slices\ConversationTurnEventKind;
use Phalanx\Harness\Ui\Slices\ConversationTurnEventProjection;
use Phalanx\Harness\Ui\Slices\ConversationTurnEventSeverity;
use Phalanx\Panoply\Effect\Kind as EffectKind;

final class ConversationEventFormatter
{
    public static function summary(ConversationTurnEvent $event): string
    {
        $projection = $event->projection;
        $body = self::body($projection, includeInspectionFields: false);

        return $body === ''
            ? $projection->label
            : $projection->label . ': ' . $body;
    }

    public static function detail(ConversationTurnEvent $event): string
    {
        $cueType = $event->cue === null ? $event->projection->kind->value : $event->cue->type;
        $body = self::body($event->projection, includeInspectionFields: true);

        return trim($event->id . ' ' . $event->projection->kind->value . ' ' . $cueType . ' ' . $body);
    }

    /**
     * @param list<ConversationTurnEvent> $events
     * @return list<ConversationEventRenderLine>
     */
    public static function threadLines(array $events): array
    {
        $lines = [];
        $consumedEffectIds = [];

        foreach ($events as $event) {
            if (!self::isThreadVisible($event)) {
                continue;
            }

            $effectId = $event->projection->effectId;
            if ($effectId !== null && self::isEffectLifecycle($event)) {
                if (isset($consumedEffectIds[$effectId])) {
                    continue;
                }

                $lines[] = self::effectLifecycleLine($effectId, $events);
                $consumedEffectIds[$effectId] = true;

                continue;
            }

            $lines[] = new ConversationEventRenderLine(
                text: self::summary($event),
                severity: $event->projection->severity,
            );
        }

        return $lines;
    }

    private static function body(ConversationTurnEventProjection $projection, bool $includeInspectionFields): string
    {
        $parts = [];

        if ($projection->effectKind !== null || $projection->effectId !== null) {
            $parts[] = trim(($projection->effectKind ?? '') . ' ' . ($projection->effectId ?? ''));
        }

        if ($projection->resolution !== null && $projection->toolName !== null) {
            $parts[] = $projection->resolution->value . ' ' . $projection->toolName;
        }

        if ($projection->summary !== null && $projection->summary !== '') {
            $parts[] = $projection->summary;
        }

        if ($includeInspectionFields && $projection->arguments !== []) {
            $parts[] = 'args ' . json_encode($projection->arguments, JSON_THROW_ON_ERROR);
        }

        if ($includeInspectionFields && $projection->argumentsDelta !== null) {
            $parts[] = 'args delta ' . $projection->argumentsDelta;
        }

        if ($includeInspectionFields && $projection->argsHash !== null) {
            $parts[] = 'args hash ' . $projection->argsHash;
        }

        if ($includeInspectionFields && $projection->structuredDelta !== null) {
            $parts[] = 'structured delta ' . $projection->structuredDelta;
        }

        if ($projection->artifactId !== null) {
            $parts[] = 'artifact ' . $projection->artifactId;
        }

        if ($projection->contentHash !== null) {
            $parts[] = $projection->contentHash;
        }

        if ($projection->provider !== null) {
            $parts[] = trim($projection->provider . ' ' . ($projection->model ?? ''));
        }

        if ($projection->clientId !== null) {
            $parts[] = 'client ' . $projection->clientId;
        }

        if ($projection->grantId !== null) {
            $parts[] = 'grant ' . $projection->grantId;
        }

        if ($projection->subject !== null) {
            $parts[] = 'subject ' . $projection->subject;
        }

        if ($projection->scope !== null && $projection->hazardCeiling !== null) {
            $parts[] = 'scope ' . $projection->scope . ' hazard ' . $projection->hazardCeiling;
        }

        if ($includeInspectionFields && $projection->allowedEffects !== []) {
            $parts[] = 'allows ' . implode(
                ', ',
                array_map(static fn(EffectKind $kind): string => $kind->value, $projection->allowedEffects),
            );
        }

        if ($includeInspectionFields && $projection->conditions !== []) {
            $parts[] = 'conditions ' . json_encode($projection->conditions, JSON_THROW_ON_ERROR);
        }

        if ($projection->expiresAt !== null) {
            $parts[] = 'expires ' . $projection->expiresAt->format(\DateTimeInterface::RFC3339);
        }

        if ($projection->cacheReadTokens !== null && $projection->cacheReadTokens > 0) {
            $parts[] = 'cache read ' . $projection->cacheReadTokens;
        }

        if ($projection->cacheWriteTokens !== null && $projection->cacheWriteTokens > 0) {
            $parts[] = 'cache write ' . $projection->cacheWriteTokens;
        }

        if ($projection->errorClass !== null) {
            $parts[] = $projection->errorClass;
        }

        if ($projection->costUsd !== null) {
            $parts[] = '$' . number_format($projection->costUsd, 4);
        }

        return implode(' · ', array_values(array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    private static function isThreadVisible(ConversationTurnEvent $event): bool
    {
        if (!$event->projection->rendersInThread()) {
            return false;
        }

        return $event->projection->kind !== ConversationTurnEventKind::EffectArgumentsDelta;
    }

    private static function isEffectLifecycle(ConversationTurnEvent $event): bool
    {
        return match ($event->projection->kind) {
            ConversationTurnEventKind::EffectAuthorized,
            ConversationTurnEventKind::EffectDenied,
            ConversationTurnEventKind::EffectExecuted,
            ConversationTurnEventKind::EffectFailed,
            ConversationTurnEventKind::EffectPaused,
            ConversationTurnEventKind::EffectRequested => true,
            default => false,
        };
    }

    /**
     * @param list<ConversationTurnEvent> $events
     */
    private static function effectLifecycleLine(string $effectId, array $events): ConversationEventRenderLine
    {
        $group = array_values(array_filter(
            $events,
            static fn(ConversationTurnEvent $event): bool => $event->projection->effectId === $effectId
                && self::isEffectLifecycle($event),
        ));

        if ($group === []) {
            return new ConversationEventRenderLine(
                text: 'effect: ' . $effectId,
                severity: ConversationTurnEventSeverity::Info,
            );
        }

        $requested = self::first($group, ConversationTurnEventKind::EffectRequested);
        $authorization = self::lastOfKind($group, ConversationTurnEventKind::EffectAuthorized);
        $terminal = self::last($group);

        $label = self::effectLifecycleLabel($terminal);
        $body = self::effectLifecycleBody($effectId, $requested, $authorization, $terminal);

        return new ConversationEventRenderLine(
            text: $body === '' ? $label : $label . ': ' . $body,
            severity: $terminal->projection->severity,
        );
    }

    /**
     * @param list<ConversationTurnEvent> $events
     */
    private static function first(array $events, ConversationTurnEventKind $kind): ?ConversationTurnEvent
    {
        return array_find(
            $events,
            static fn(ConversationTurnEvent $event): bool => $event->projection->kind === $kind,
        );
    }

    /**
     * @param list<ConversationTurnEvent> $events
     */
    private static function lastOfKind(array $events, ConversationTurnEventKind $kind): ?ConversationTurnEvent
    {
        $match = null;

        foreach ($events as $event) {
            if ($event->projection->kind === $kind) {
                $match = $event;
            }
        }

        return $match;
    }

    /**
     * @param non-empty-list<ConversationTurnEvent> $events
     */
    private static function last(array $events): ConversationTurnEvent
    {
        return $events[array_key_last($events)];
    }

    private static function effectLifecycleLabel(ConversationTurnEvent $event): string
    {
        return match ($event->projection->kind) {
            ConversationTurnEventKind::EffectAuthorized => 'effect approved',
            ConversationTurnEventKind::EffectDenied => 'effect denied',
            ConversationTurnEventKind::EffectExecuted => 'effect executed',
            ConversationTurnEventKind::EffectFailed => 'effect failed',
            ConversationTurnEventKind::EffectPaused => 'approval needed',
            ConversationTurnEventKind::EffectRequested => $event->projection->requiresApproval
                ? 'approval needed'
                : 'effect requested',
            default => $event->projection->label,
        };
    }

    private static function effectLifecycleBody(
        string $effectId,
        ?ConversationTurnEvent $requested,
        ?ConversationTurnEvent $authorization,
        ConversationTurnEvent $terminal,
    ): string {
        $projection = $terminal->projection;
        $request = $requested === null ? null : $requested->projection;
        $approval = $authorization === null ? null : $authorization->projection;
        $effectKind = $request === null ? $projection->effectKind : ($request->effectKind ?? $projection->effectKind);
        $grantId = $projection->grantId ?? $approval?->grantId;
        $parts = [
            trim(($effectKind ?? '') . ' ' . $effectId),
        ];

        if ($request !== null && $request->summary !== null && $request->summary !== '') {
            $parts[] = $request->summary;
        }

        if ($projection->reason !== null && $projection->reason !== '') {
            $parts[] = $projection->reason;
        }

        if ($projection->reasonCodes !== []) {
            $parts[] = implode(', ', $projection->reasonCodes);
        }

        if ($grantId !== null && $grantId !== '') {
            $parts[] = 'grant ' . $grantId;
        }

        if ($projection->durationMs !== null) {
            $parts[] = $projection->durationMs . 'ms';
        }

        if ($projection->resultDigest !== null && $projection->resultDigest !== '') {
            $parts[] = $projection->resultDigest;
        }

        if ($projection->errorClass !== null && $projection->errorClass !== '') {
            $parts[] = $projection->errorClass;
        }

        return implode(' · ', array_values(array_filter($parts, static fn(string $part): bool => $part !== '')));
    }
}
