<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Acme\ResearchHandler;
use Phalanx\Ai\AiServiceBundle;
use Phalanx\Application;
use Phalanx\Http\Runner;
use Phalanx\WebSocket\WsRouteGroup;

$app = Application::starting()
    ->providers(new AiServiceBundle())
    ->compile();

$wsRoutes = WsRouteGroup::of([
    '/research' => ResearchHandler::class,
]);

echo <<<'BOOT'
Research Agent Server
=====================
Listening on http://0.0.0.0:8080

WebSocket: ws://localhost:8080/research
Send: {"type":"research","documents":[...],"question":"..."}

BOOT;

Runner::from($app)
    ->withWebsockets($wsRoutes)
    ->run('0.0.0.0:8080');
