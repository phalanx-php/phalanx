<?php

declare(strict_types=1);

/**
 * Multi-Tenant Fleet - Worker Process
 *
 * Worker processes subscribe to the `agent:tasks` Redis channel and execute
 * agent turns. Multiple workers run concurrently -- Redis pub/sub distributes
 * work naturally.
 *
 * Each task runs in a child scope. If a tenant's agent crashes, only that
 * child scope tears down. The worker continues listening for new tasks.
 *
 * Usage:
 *   php worker.php
 */

/*
 * In a real Phalanx app:
 *
 * $app = Application::starting($context)
 *     ->providers(
 *         new AiServiceBundle(),
 *         new RedisServiceBundle(),
 *         new PgServiceBundle(),
 *         new WorkerBundle(),
 *     )
 *     ->compile();
 *
 * $scope = $app->createScope();
 *
 * $scope->service(RedisPubSub::class)->subscribe(
 *     'agent:tasks',
 *     static function (string $message) use ($scope) {
 *         $task = json_decode($message, true);
 *         $tenantId = $task['tenant_id'];
 *         $sessionId = $task['session_id'];
 *
 *         $childScope = $scope->child()
 *             ->withAttribute('tenant.id', $tenantId)
 *             ->withAttribute('session.id', $sessionId);
 *
 *         $factory = $childScope->service(TenantAgentFactory::class);
 *         $agent = $factory->create($tenantId);
 *
 *         $memory = $childScope->service(ConversationMemory::class);
 *         $conversation = $memory->load($sessionId);
 *
 *         $turn = Turn::begin($agent)
 *             ->conversation($conversation)
 *             ->message(Message::user($task['message']))
 *             ->maxSteps(6);
 *
 *         $events = AgentLoop::run($turn, $childScope);
 *
 *         $pubsub = $childScope->service(RedisPubSub::class);
 *
 *         // Stream tokens and tool activity to gateway via Redis
 *         foreach ($events($childScope) as $event) {
 *             if ($event->kind === AgentEventKind::TokenDelta) {
 *                 $pubsub->publish("session:{$sessionId}:response", json_encode([
 *                     'type' => 'token',
 *                     'text' => $event->data->text,
 *                 ]));
 *             }
 *
 *             if ($event->kind === AgentEventKind::ToolCallStart) {
 *                 $pubsub->publish("session:{$sessionId}:response", json_encode([
 *                     'type' => 'thinking',
 *                     'tool' => $event->data->toolName,
 *                 ]));
 *             }
 *         }
 *
 *         $result = AgentResult::awaitFrom($events, $childScope);
 *         $memory->save($sessionId, $result->conversation);
 *
 *         $pubsub->publish("session:{$sessionId}:response", json_encode([
 *             'type' => 'complete',
 *             'tokens' => $result->usage->total,
 *         ]));
 *
 *         $childScope->dispose();
 *     }
 * );
 */

echo "Multi-Tenant Fleet - Worker\n";
echo "===========================\n\n";
echo "This example demonstrates a worker process that executes agent turns.\n";
echo "Multiple worker instances can run concurrently for horizontal scaling.\n\n";
echo "Flow:\n";
echo "  1. Worker subscribes to 'agent:tasks' Redis channel\n";
echo "  2. Picks up task with tenant_id, session_id, message\n";
echo "  3. Resolves tenant-specific agent via TenantAgentFactory\n";
echo "  4. Loads conversation history from ConversationMemory\n";
echo "  5. Executes agent turn, streaming tokens via Redis pub/sub\n";
echo "  6. Saves updated conversation, sends completion event\n";
echo "  7. Disposes child scope, continues listening\n";
