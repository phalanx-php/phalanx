<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Render;

use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Theatron\Template\Slice\ConversationTurnEvent;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventProjection;

class ConversationEventFormatter
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
}
