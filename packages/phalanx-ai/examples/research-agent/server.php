<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Acme\ResearchAgent;
use Phalanx\Ai\AiServiceBundle;
use Phalanx\Ai\AgentLoop;
use Phalanx\Ai\Event\AgentEventKind;
use Phalanx\Ai\Message\Message;
use Phalanx\Ai\Turn;
use Phalanx\Application;
use Phalanx\Http\Runner;
use Phalanx\WebSocket\WsMessage;
use Phalanx\WebSocket\WsRoute;
use Phalanx\WebSocket\WsRouteGroup;

$app = Application::starting()
    ->providers(new AiServiceBundle())
    ->compile();

$wsRoutes = WsRouteGroup::of([
    '/research' => new WsRoute(
        fn: static function ($scope): void {
            $conn = $scope->connection;

            foreach ($conn->inbound->consume() as $msg) {
                if (!$msg->isText) {
                    continue;
                }

                $request = $msg->decode();
                if ($request['type'] !== 'research') {
                    continue;
                }

                $documents = $request['documents'];
                $question = $request['question'];

                $documentList = implode("\n", array_map(
                    static fn($d) => "- {$d['name']} ({$d['type']}): {$d['path']}",
                    $documents
                ));

                $turn = Turn::begin(new ResearchAgent())
                    ->message(Message::user(
                        "Documents:\n{$documentList}\n\n" .
                        "Research question: {$question}"
                    ))
                    ->maxSteps(8);

                $events = AgentLoop::run($turn, $scope);

                foreach ($events($scope) as $event) {
                    match ($event->kind) {
                        AgentEventKind::ToolCallStart => $conn->send(WsMessage::json([
                            'type' => 'progress',
                            'stage' => 'tool',
                            'tool' => $event->data->toolName,
                        ])),
                        AgentEventKind::ToolCallComplete => $conn->send(WsMessage::json([
                            'type' => 'progress',
                            'stage' => 'tool_done',
                            'tool' => $event->data->toolName,
                            'ms' => $event->elapsed,
                        ])),
                        AgentEventKind::TokenDelta => $conn->send(WsMessage::json([
                            'type' => 'token',
                            'text' => $event->data->text,
                        ])),
                        AgentEventKind::AgentComplete => $conn->send(WsMessage::json([
                            'type' => 'complete',
                            'tokens' => $event->data->usage->total,
                            'steps' => $event->data->steps,
                        ])),
                        default => null,
                    };
                }
            }
        },
    ),
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
