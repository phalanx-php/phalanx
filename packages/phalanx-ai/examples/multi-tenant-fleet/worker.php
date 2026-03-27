<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Acme\TenantAgentFactory;
use Phalanx\Ai\AgentLoop;
use Phalanx\Ai\AgentResult;
use Phalanx\Ai\AiServiceBundle;
use Phalanx\Ai\Event\AgentEventKind;
use Phalanx\Ai\Memory\ConversationMemory;
use Phalanx\Ai\Message\Message;
use Phalanx\Ai\Turn;
use Phalanx\Application;
use Phalanx\Postgres\PgServiceBundle;
use Phalanx\Redis\RedisPubSub;
use Phalanx\Redis\RedisServiceBundle;

$app = Application::starting()
    ->providers(
        new AiServiceBundle(),
        new RedisServiceBundle(),
        new PgServiceBundle(),
    )
    ->compile();

$scope = $app->createScope();

echo <<<'BOOT'
Multi-Tenant Fleet - Worker
============================
Subscribed to agent:tasks Redis channel
Waiting for tasks...

BOOT;

$scope->service(RedisPubSub::class)->subscribe(
    'agent:tasks',
    static function (string $message) use ($scope) {
        $task = json_decode($message, true);
        $tenantId = $task['tenant_id'];
        $sessionId = $task['session_id'];

        $childScope = $scope->child()
            ->withAttribute('tenant.id', $tenantId)
            ->withAttribute('session.id', $sessionId);

        $factory = $childScope->service(TenantAgentFactory::class);
        $agent = $factory->create($tenantId);

        $memory = $childScope->service(ConversationMemory::class);
        $conversation = $memory->load($sessionId);

        $turn = Turn::begin($agent)
            ->conversation($conversation)
            ->message(Message::user($task['message']))
            ->maxSteps(6);

        $events = AgentLoop::run($turn, $childScope);

        $pubsub = $childScope->service(RedisPubSub::class);

        // Stream tokens and tool activity to gateway via Redis
        foreach ($events($childScope) as $event) {
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

        $result = AgentResult::awaitFrom($events, $childScope);
        $memory->save($sessionId, $result->conversation);

        $pubsub->publish("session:{$sessionId}:response", json_encode([
            'type' => 'complete',
            'tokens' => $result->usage->total,
        ]));

        $childScope->dispose();
    }
);
