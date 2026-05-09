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
/** @var array<string, mixed> $context */
$context = phalanxAthenaExampleContext($argv ?? []);

try {
    $server = Stoa::starting($context)
        ->providers(new AiServiceBundle())
        ->websockets($wsRoutes)
        ->listen('0.0.0.0:8080');
} catch (\LogicException $e) {
    echo <<<'BOOT'
Research Agent Server
=====================
Status: unavailable

This demo registers WebSocket routes through the Stoa builder. That entry point
redirects callers to Hermes. Wire the WebSocket routes through the Hermes
integration before running this demo.

Reason returned by Stoa:

BOOT;

    printf("  %s\n", $e->getMessage());
    echo "\n";
    exit(0);
}

echo <<<'BOOT'
Research Agent Server
=====================
Status: starting

Listening on http://0.0.0.0:8080

WebSocket endpoint:
  ws://localhost:8080/research

Example JSON:
  {"type":"research","documents":[...],"question":"How do Athena's wisdom and warcraft roles differ across these sources?"}

BOOT;

try {
    $server->run();
} catch (\Throwable $e) {
    phalanxAthenaExamplePrintServerFailure($e, '0.0.0.0:8080');
    exit(1);
}
