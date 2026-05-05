<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\TriageHandler;
use Phalanx\Athena\AiServiceBundle;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Stoa;

$routes = RouteGroup::of([
    'POST /triage' => TriageHandler::class,
]);
/** @var array<string, mixed> $context */
$context = phalanxAthenaExampleContext($argv ?? []);

echo <<<'BOOT'
Support Triage Server
=====================
Status: starting

Listening on http://0.0.0.0:8080

Endpoint:
  POST http://localhost:8080/triage

Example JSON:
  {"ticket_id":123,"customer_email":"sarah@example.com","subject":"...","body":"..."}

Provider configuration:

BOOT;

printf("  %-18s %s\n", 'ATHENA_DEMO_LIVE', phalanxAthenaExampleLiveMode() ? 'enabled' : 'disabled');
printf("  %-18s %s\n", 'ANTHROPIC_API_KEY', phalanxAthenaExampleEnvStatus('ANTHROPIC_API_KEY', requiresLive: true));
printf("  %-18s %s\n", 'OPENAI_API_KEY', phalanxAthenaExampleEnvStatus('OPENAI_API_KEY', requiresLive: true));

$instructions = <<<'BOOT'

Run with a hosted provider:
  ATHENA_DEMO_LIVE=1 ANTHROPIC_API_KEY=... %s

BOOT;

printf(
    $instructions,
    phalanxAthenaExampleComposerCommand(
        'demo:athena:serve:support-triage',
        'demo:serve:support-triage',
    ),
);

try {
    Stoa::starting($context)
        ->providers(new AiServiceBundle())
        ->routes($routes)
        ->listen('0.0.0.0:8080')
        ->run();
} catch (\Throwable $e) {
    phalanxAthenaExamplePrintServerFailure($e, '0.0.0.0:8080');
    exit(1);
}
