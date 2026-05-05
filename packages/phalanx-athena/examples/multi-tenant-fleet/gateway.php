<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\Fleet\CustomerChatHandler;
use Acme\Fleet\HealthCheck;
use Phalanx\Athena\AiServiceBundle;
use Phalanx\Hermes\WsRouteGroup;
use Phalanx\Redis\RedisServiceBundle;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Stoa;

$customerWs = WsRouteGroup::of([
    '/ws/chat/{tenantId}/{sessionId}' => CustomerChatHandler::class,
]);

$httpRoutes = RouteGroup::of([
    'GET /health' => HealthCheck::class,
]);
/** @var array<string, mixed> $context */
$context = phalanxAthenaExampleContext($argv ?? []);

$server = Stoa::starting($context)
    ->providers(
        new AiServiceBundle(),
        new RedisServiceBundle(),
    )
    ->routes($httpRoutes)
    ->listen('0.0.0.0:8080');

$websocketStatus = 'WebSocket: reserved for native Stoa support in a later slice';

try {
    $server->websockets($customerWs);
    $websocketStatus = 'WebSocket: ws://localhost:8080/ws/chat/{tenantId}/{sessionId}';
} catch (\LogicException $e) {
    $websocketStatus = 'WebSocket: ' . $e->getMessage();
}

echo <<<BOOT
Multi-Tenant Fleet - Gateway
=============================
Listening on http://0.0.0.0:8080

$websocketStatus
Health:    GET http://localhost:8080/health

BOOT;

$server->run();
