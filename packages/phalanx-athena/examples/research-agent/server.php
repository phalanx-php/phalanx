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

Nothing is wrong with Athena. This demo needs native Stoa WebSocket support,
which is reserved for a later runtime slice.

Current blocker:

BOOT;

    printf("  %s\n", $e->getMessage());

    echo <<<'BOOT'

No environment variable can fix this in the current source tree.

BOOT;
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
  {"type":"research","documents":[...],"question":"..."}

BOOT;

try {
    $server->run();
} catch (\Throwable $e) {
    phalanxAthenaExamplePrintServerFailure($e, '0.0.0.0:8080');
    exit(1);
}
