<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Event;

use Phalanx\Theatron\Stream\StreamEvent;

final class AgentOnlineEvent implements StreamEvent
{
    public function __construct(
        private(set) string $agentId,
        private(set) string $agentName,
    ) {
    }
}
