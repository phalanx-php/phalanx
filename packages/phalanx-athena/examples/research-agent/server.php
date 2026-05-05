<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\ResearchHandler;
use Phalanx\Athena\AiServiceBundle;
use Phalanx\Hermes\WsRouteGroup;
use Phalanx\Stoa\Stoa;

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

try {
    Stoa::starting()
        ->providers(new AiServiceBundle())
        ->websockets($wsRoutes)
        ->listen('0.0.0.0:8080')
        ->run();
} catch (\LogicException $e) {
    echo $e->getMessage() . "\n";
    exit(0);
}
