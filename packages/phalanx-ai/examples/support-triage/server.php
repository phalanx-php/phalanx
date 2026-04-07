<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Acme\TriageHandler;
use Phalanx\Ai\AiServiceBundle;
use Phalanx\Application;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;

$app = Application::starting()
    ->providers(new AiServiceBundle())
    ->compile();

$routes = RouteGroup::of([
    'POST /triage' => TriageHandler::class,
]);

echo <<<'BOOT'
Support Triage Server
=====================
Listening on http://0.0.0.0:8080

POST /triage  {"ticket_id":123,"customer_email":"sarah@example.com","subject":"...","body":"..."}

BOOT;

Runner::from($app)
    ->withRoutes($routes)
    ->run('0.0.0.0:8080');
