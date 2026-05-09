<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Message\Message;
use Phalanx\Athena\Turn;
use Phalanx\Task\Scopeable;
use Phalanx\Hermes\WsMessage;
use Phalanx\Hermes\WsScope;

final class ResearchHandler implements Scopeable
{
    public function __invoke(WsScope $scope): void
    {
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
    }
}
