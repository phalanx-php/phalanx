<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Acme\SupportTriageAgent;
use Acme\TriageResult;
use Phalanx\Ai\AiServiceBundle;
use Phalanx\Ai\AgentLoop;
use Phalanx\Ai\Event\AgentEventKind;
use Phalanx\Ai\Message\Message;
use Phalanx\Ai\Stream\TokenAccumulator;
use Phalanx\Ai\Turn;
use Phalanx\Application;
use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use Phalanx\Http\Sse\SseResponse;

$app = Application::starting()
    ->providers(new AiServiceBundle())
    ->compile();

$routes = RouteGroup::of([
    'POST /triage' => Route::of(
        fn: static function ($scope) {
            $body = json_decode((string) $scope->request->getBody(), true);

            $turn = Turn::begin(new SupportTriageAgent())
                ->message(Message::user(
                    "Ticket from: {$body['customer_email']}\n" .
                    "Subject: {$body['subject']}\n\n" .
                    $body['body']
                ))
                ->output(TriageResult::class)
                ->maxSteps(4);

            $events = AgentLoop::run($turn, $scope);
            $accumulator = TokenAccumulator::from($events, $scope);

            // Persist triage result after SSE stream completes
            $scope->onDispose(static function () use ($accumulator) {
                $result = $accumulator->result();
                if ($result->structured !== null) {
                    // Save to database via $scope->service(PgPool::class)
                }
            });

            return SseResponse::from(
                $accumulator->events()
                    ->filter(static fn($e) => $e->kind->isUserFacing())
                    ->map(static fn($e) => json_encode(match ($e->kind) {
                        AgentEventKind::TokenDelta => [
                            'type' => 'token',
                            'text' => $e->data->text,
                        ],
                        AgentEventKind::ToolCallStart => [
                            'type' => 'tool_start',
                            'tool' => $e->data->toolName,
                        ],
                        AgentEventKind::ToolCallComplete => [
                            'type' => 'tool_done',
                            'tool' => $e->data->toolName,
                            'ms' => $e->elapsed,
                        ],
                        AgentEventKind::StructuredOutput => [
                            'type' => 'triage',
                            'priority' => $e->data->value->priority->value,
                            'category' => $e->data->value->category->value,
                            'auto_resolvable' => $e->data->value->autoResolvable,
                        ],
                        default => ['type' => 'event', 'kind' => $e->kind->value],
                    })),
                $scope,
                event: 'triage',
            );
        },
    ),
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
