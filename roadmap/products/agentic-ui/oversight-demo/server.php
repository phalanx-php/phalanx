<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Phalanx\Agentic\AgenticServiceBundle;
use Phalanx\Agentic\Ws\AgentConnectionHandler;
use Phalanx\Hermes\WsGateway;
use Phalanx\Hermes\WsRouteGroup;
use Phalanx\Stoa\Stoa;

$wsRoutes = WsRouteGroup::of([
    '/agent-ws' => AgentConnectionHandler::class,
], new WsGateway());

$context = [
    'app_name'            => 'Agentic Oversight Demo',
    'agentic_workspace'   => 'global',
];

$server = Stoa::starting($context)
    ->providers(new AgenticServiceBundle())
    ->websockets($wsRoutes)
    ->listen('0.0.0.0:8090');

echo "Agentic Oversight Demo listening on http://localhost:8090\n";
