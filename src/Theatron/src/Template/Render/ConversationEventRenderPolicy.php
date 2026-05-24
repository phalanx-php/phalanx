<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Render;

use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Template\Slice\ConversationTurnEvent;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventSeverity;

class ConversationEventRenderPolicy
{
    public static function marker(ConversationTurnEvent $event): string
    {
        return self::markerForSeverity($event->projection->severity);
    }

    public static function markerForSeverity(ConversationTurnEventSeverity $severity): string
    {
        return match ($severity) {
            ConversationTurnEventSeverity::Error => '!',
            ConversationTurnEventSeverity::Success => '✓',
            ConversationTurnEventSeverity::Warning => '?',
            ConversationTurnEventSeverity::Info,
            ConversationTurnEventSeverity::Muted => '·',
        };
    }

    public static function style(ConversationTurnEventSeverity $severity): TextStyle
    {
        return match ($severity) {
            ConversationTurnEventSeverity::Error => TextStyle::new()->fg(Color::indexed(203))->bold(),
            ConversationTurnEventSeverity::Success => TextStyle::new()->fg(Color::indexed(114)),
            ConversationTurnEventSeverity::Warning => TextStyle::new()->fg(Color::indexed(214)),
            ConversationTurnEventSeverity::Info => TextStyle::new()->fg(Color::indexed(245)),
            ConversationTurnEventSeverity::Muted => TextStyle::new()->fg(Color::indexed(242))->dim(),
        };
    }
}
