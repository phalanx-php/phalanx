<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Render;

use Phalanx\Harness\Ui\Slices\ConversationTurnEventSeverity;

final class ConversationEventRenderLine
{
    public function __construct(
        private(set) string $text,
        private(set) ConversationTurnEventSeverity $severity,
    ) {
    }
}
