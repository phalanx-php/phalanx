<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\AgentResult;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Memory\ConversationMemory;
use Phalanx\Athena\Message\Message;
use Phalanx\Athena\Turn;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Redis\RedisPubSub;
use Phalanx\Task\Executable;
use Phalanx\Hermes\WsMessage;

final class HandleAgentTask implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        $raw = $scope->attribute('subscription.message');
        $task = json_decode((string) $raw, true);
        $tenantId = $task['tenant_id'];
        $sessionId = $task['session_id'];

        $scope = $scope->withAttribute('tenant.id', $tenantId);
        $scope = $scope->withAttribute('session.id', $sessionId);

        $factory = $scope->service(TenantAgentFactory::class);
        $agent = $factory->create($tenantId);

        $memory = $scope->service(ConversationMemory::class);
        $conversation = $memory->load($sessionId);

        $turn = Turn::begin($agent)
            ->conversation($conversation)
            ->message(Message::user($task['message']))
            ->maxSteps(6);

        $events = AgentLoop::run($turn, $scope);
        $pubsub = $scope->service(RedisPubSub::class);

        foreach ($events($scope) as $event) {
            if ($event->kind === AgentEventKind::TokenDelta) {
                $pubsub->publish("session:{$sessionId}:response", json_encode([
                    'type' => 'token',
                    'text' => $event->data->text,
                ]));
            }

            if ($event->kind === AgentEventKind::ToolCallStart) {
                $pubsub->publish("session:{$sessionId}:response", json_encode([
                    'type' => 'thinking',
                    'tool' => $event->data->toolName,
                ]));
            }
        }

        $result = AgentResult::awaitFrom($events, $scope);
        $memory->save($sessionId, $result->conversation);

        $pubsub->publish("session:{$sessionId}:response", json_encode([
            'type' => 'complete',
            'tokens' => $result->usage->total,
        ]));

        return null;
    }
}
