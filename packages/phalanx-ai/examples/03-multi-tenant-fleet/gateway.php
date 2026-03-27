<?php

declare(strict_types=1);

/**
 * Multi-Tenant Fleet - Gateway Process
 *
 * The gateway handles all WebSocket connections and routes events between
 * customers, AI workers, and human agents. It does NOT run agents itself --
 * agent execution happens in separate worker processes.
 *
 * Architecture:
 *   Gateway (this) <--Redis pub/sub--> Worker 1, Worker 2, ... Worker N
 *       |
 *       +-- Customer WebSocket connections
 *       +-- Human agent dashboard WebSocket connections
 *       +-- HTTP health/stats endpoints
 *
 * Usage:
 *   php gateway.php
 */

/*
 * In a real Phalanx app:
 *
 * $app = Application::starting($context)
 *     ->providers(
 *         new AiServiceBundle(),
 *         new RedisServiceBundle(),
 *         new PgServiceBundle(),
 *         new GatewayBundle(),
 *     )
 *     ->compile();
 *
 * $customerWs = WsRouteGroup::of([
 *     '/ws/chat/{tenantId}/{sessionId}' => new WsRoute(
 *         fn: static function (WsScope $scope): void {
 *             $conn = $scope->connection;
 *             $tenantId = $scope->params->get('tenantId');
 *             $sessionId = $scope->params->get('sessionId');
 *             $gateway = $scope->service(WsGateway::class);
 *
 *             $gateway->register($conn);
 *
 *             $scope->concurrent([
 *                 // Listen for agent responses via Redis
 *                 Task::of(static function ($s) use ($sessionId, $conn) {
 *                     $s->service(RedisPubSub::class)->subscribe(
 *                         "session:{$sessionId}:response",
 *                         static function (string $message) use ($conn) {
 *                             if ($conn->isOpen) {
 *                                 $conn->send(WsMessage::text($message));
 *                             }
 *                         }
 *                     );
 *                 }),
 *
 *                 // Handle inbound customer messages
 *                 Task::of(static function ($s) use ($conn, $tenantId, $sessionId) {
 *                     foreach ($conn->inbound->consume() as $msg) {
 *                         if (!$msg->isText) continue;
 *                         $input = $msg->json();
 *
 *                         $s->service(RedisPubSub::class)->publish(
 *                             'agent:tasks',
 *                             json_encode([
 *                                 'tenant_id' => $tenantId,
 *                                 'session_id' => $sessionId,
 *                                 'message' => $input['text'],
 *                                 'type' => 'customer_message',
 *                             ])
 *                         );
 *                     }
 *                 }),
 *             ]);
 *
 *             $gateway->unregister($conn);
 *         },
 *     ),
 * ]);
 *
 * Runner::from($app)
 *     ->withRoutes($httpRoutes)
 *     ->withWebsockets($customerWs->merge($agentDashboardWs))
 *     ->run('0.0.0.0:8080');
 */

echo "Multi-Tenant Fleet - Gateway\n";
echo "============================\n\n";
echo "This example demonstrates the gateway process for a multi-tenant\n";
echo "AI support platform.\n\n";
echo "Architecture:\n";
echo "  - Gateway handles WebSocket connections (customers + human agents)\n";
echo "  - Workers execute agent turns in separate processes\n";
echo "  - Redis pub/sub bridges gateway <-> workers\n";
echo "  - Postgres stores conversations, tenant configs, escalations\n\n";
echo "Key capabilities:\n";
echo "  - Hundreds of concurrent conversations across dozens of tenants\n";
echo "  - Real-time token streaming from worker to customer via Redis\n";
echo "  - Live escalation to human agents with conversation context\n";
echo "  - Tenant-specific agent configs loaded at runtime (cached in Redis)\n";
echo "  - Single-port gateway: HTTP + customer WS + dashboard WS\n";
