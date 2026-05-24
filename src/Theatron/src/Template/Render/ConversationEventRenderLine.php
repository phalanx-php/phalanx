<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Render;

use Phalanx\Theatron\Template\Slice\ConversationTurnEventSeverity;

final class ConversationEventRenderLine
{
    public function __construct(
        private(set) string $text,
        private(set) ConversationTurnEventSeverity $severity,
    ) {
    }
}
