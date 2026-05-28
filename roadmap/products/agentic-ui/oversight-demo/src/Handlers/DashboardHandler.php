<?php

declare(strict_types=1);

namespace App\Handlers;

use Phalanx\Agentic\Supervisor\ConversationSupervisor;
use Phalanx\Scope\RequestContext;

final class DashboardHandler
{
    public function globalFeed(RequestContext $ctx): array
    {
        $supervisor = $ctx->service(ConversationSupervisor::class);
        $state = $ctx->execute($supervisor);
        return [
            'title' => 'Global Agent Oversight',
            'data'  => $state,
        ];
    }
}
