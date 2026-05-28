<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Ws;

use Phalanx\Agentic\AgentSession\AgentSessionRegistry;
use Phalanx\Agentic\Signal\UiIntentSignal;
use Phalanx\Hermes\WsConnection;
use Phalanx\Hermes\WsConnectionHandler;
use Phalanx\Scope\ExecutionScope;

final class AgentConnectionHandler implements WsConnectionHandler
{
    public function __construct(
        private readonly AgentSessionRegistry $registry,
    ) {}

    public function onOpen(ExecutionScope $scope, WsConnection $conn): void
    {
        $conn->send(json_encode(['type' => 'welcome', 'message' => 'agentic ws ready']));
    }

    public function onMessage(ExecutionScope $scope, WsConnection $conn, string $message): void
    {
        $data = json_decode($message, true) ?? [];

        if (isset($data['intent'])) {
            // Bidirectional control
            $signal = new UiIntentSignal(
                $data['session_id'],
                $data['intent'],
                $data['payload'] ?? []
            );
            $scope->service(\Phalanx\Eidolon\Signal\SignalCollector::class)->add($signal);
        }

        // Normal composer flow handled via AgentWsGateway
    }

    public function onClose(ExecutionScope $scope, WsConnection $conn): void
    {
        // Supervisor will clean up after timeout
    }
}
