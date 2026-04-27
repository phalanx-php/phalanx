<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Acme\Fleet\CustomerChatHandler;
use Acme\Fleet\HealthCheck;
use Phalanx\Athena\AiServiceBundle;
use Phalanx\Application;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Runner;
use Phalanx\Redis\RedisServiceBundle;
use Phalanx\Hermes\WsRouteGroup;

$app = Application::starting()
    ->providers(
        new AiServiceBundle(),
        new RedisServiceBundle(),
    )
    ->compile();

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

Runner::from($app)
    ->withRoutes($httpRoutes)
    ->withWebsockets($customerWs)
    ->run('0.0.0.0:8080');
