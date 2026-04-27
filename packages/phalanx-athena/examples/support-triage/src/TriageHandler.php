<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Message\Message;
use Phalanx\Athena\Stream\TokenAccumulator;
use Phalanx\Athena\Turn;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Sse\SseResponse;
use Phalanx\Task\Scopeable;
use Psr\Http\Message\ResponseInterface;

final class TriageHandler implements Scopeable
{
    public function __invoke(RequestScope $scope): ResponseInterface
    {
        $body = $scope->body->json();

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

        $scope->onDispose(static function () use ($accumulator): void {
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
    }
}
