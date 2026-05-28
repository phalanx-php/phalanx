<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Composer;

use Phalanx\Agentic\AgentSession\AgentSessionRegistry;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class ComposerHandler implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        $payload = $scope->attribute('ws_payload') ?? '';
        $data = json_decode($payload, true) ?? [];
        $sessionId = $data['session_id'] ?? null;
        $message   = $data['message'] ?? '';

        if (!$sessionId || !$message) {
            return ['error' => 'missing_session_or_message'];
        }

        $registry = $scope->service(AgentSessionRegistry::class);
        $session  = $registry->get($sessionId);

        if (!$session) {
            return ['error' => 'session_not_found'];
        }

        // Slash command check
        $slash = $scope->service(SlashCommandRegistry::class);
        $result = $slash->dispatch($message, ['session' => $sessionId]);

        if ($result !== null) {
            return ['status' => 'slash_handled', 'result' => $result];
        }

        // Normal user message
        $session->handleUserMessage($scope, $message, $data['attachments'] ?? []);

        return ['status' => 'message_queued', 'session' => $sessionId];
    }
}
