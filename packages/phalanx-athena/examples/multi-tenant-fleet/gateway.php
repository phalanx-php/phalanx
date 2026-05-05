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

echo <<<'BOOT'
Multi-Tenant Fleet - Gateway
=============================
Listening on http://0.0.0.0:8080

WebSocket: ws://localhost:8080/ws/chat/{tenantId}/{sessionId}
Health:    GET http://localhost:8080/health

BOOT;

try {
    Stoa::starting()
        ->providers(
            new AiServiceBundle(),
            new RedisServiceBundle(),
        )
        ->routes($httpRoutes)
        ->websockets($customerWs)
        ->listen('0.0.0.0:8080')
        ->run();
} catch (\LogicException $e) {
    echo $e->getMessage() . "\n";
    exit(0);
}
