<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Render;

use Phalanx\Theatron\Template\Slice\ConversationTurnEvent;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventProjection;

final class ConversationEventFormatter
{
    public static function summary(ConversationTurnEvent $event): string
    {
        $projection = $event->projection;
        $body = self::body($projection);

        return $body === ''
            ? $projection->label
            : $projection->label . ': ' . $body;
    }

    public static function detail(ConversationTurnEvent $event): string
    {
        $cueType = $event->cue === null ? $event->projection->kind->value : $event->cue->type;
        $body = self::body($event->projection);

        return trim($event->id . ' ' . $event->projection->kind->value . ' ' . $cueType . ' ' . $body);
    }

    private static function body(ConversationTurnEventProjection $projection): string
    {
        $parts = [];

        if ($projection->effectKind !== null || $projection->effectId !== null) {
            $parts[] = trim(($projection->effectKind ?? '') . ' ' . ($projection->effectId ?? ''));
        }

        if ($projection->summary !== null && $projection->summary !== '') {
            $parts[] = $projection->summary;
        }

        if ($projection->grantId !== null) {
            $parts[] = 'grant ' . $projection->grantId;
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
