<?php

declare(strict_types=1);

namespace App\Handlers;

use Phalanx\Agentic\AgentSession\AgentSessionRegistry;
use Phalanx\Scope\RequestContext;

final class SessionHandler
{
    public function detail(RequestContext $ctx, string $sessionId): array
    {
        $registry = $ctx->service(AgentSessionRegistry::class);
        $session  = $registry->get($sessionId);

        $state = $session?->getState();

        return [
            'session_id' => $sessionId,
            'status'     => $state?->status() ?? 'not_found',
            'last'       => $state?->lastSignal() ?? null,
        ];
    }
}
