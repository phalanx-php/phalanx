<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Phalanx\Ai\AiServiceBundle;
use Phalanx\Application;
use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use Phalanx\Redis\RedisPubSub;
use Phalanx\Redis\RedisServiceBundle;
use Phalanx\Task\Task;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsMessage;
use Phalanx\WebSocket\WsRoute;
use Phalanx\WebSocket\WsRouteGroup;

$app = Application::starting()
    ->providers(
        new AiServiceBundle(),
        new RedisServiceBundle(),
    )
    ->compile();

$customerWs = WsRouteGroup::of([
    '/ws/chat/{tenantId}/{sessionId}' => new WsRoute(
        fn: static function ($scope): void {
            $conn = $scope->connection;
            $tenantId = $scope->params->get('tenantId');
            $sessionId = $scope->params->get('sessionId');
            $gateway = $scope->service(WsGateway::class);

            $gateway->register($conn);

            $scope->concurrent([
                // Listen for agent responses via Redis
                Task::of(static function ($s) use ($sessionId, $conn) {
                    $s->service(RedisPubSub::class)->subscribe(
                        "session:{$sessionId}:response",
                        static function (string $message) use ($conn) {
                            if ($conn->isOpen) {
                                $conn->send(WsMessage::text($message));
                            }
                        }
                    );
                }),

                // Handle inbound customer messages
                Task::of(static function ($s) use ($conn, $tenantId, $sessionId) {
                    foreach ($conn->inbound->consume() as $msg) {
                        if (!$msg->isText) {
                            continue;
                        }

                        $input = $msg->decode();

                        $s->service(RedisPubSub::class)->publish(
                            'agent:tasks',
                            json_encode([
                                'tenant_id' => $tenantId,
                                'session_id' => $sessionId,
                                'message' => $input['text'],
                                'type' => 'customer_message',
                            ])
                        );
                    }
                }),
            ]);

            $gateway->unregister($conn);
        },
    ),
]);

$httpRoutes = RouteGroup::of([
    'GET /health' => Route::of(
        fn: static fn() => ['status' => 'ok'],
    ),
]);

echo <<<'BOOT'
Multi-Tenant Fleet - Gateway
=============================
Listening on http://0.0.0.0:8080

WebSocket: ws://localhost:8080/ws/chat/{tenantId}/{sessionId}
Health:    GET http://localhost:8080/health

BOOT;

Runner::from($app)
    ->withRoutes($httpRoutes)
    ->withWebsockets($customerWs)
    ->run('0.0.0.0:8080');
