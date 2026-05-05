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
Listening on http://0.0.0.0:8080

POST /triage  {"ticket_id":123,"customer_email":"sarah@example.com","subject":"...","body":"..."}

BOOT;

Stoa::starting($context)
    ->providers(new AiServiceBundle())
    ->routes($routes)
    ->listen('0.0.0.0:8080')
    ->run();
