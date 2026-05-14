<?php

declare(strict_types=1);

namespace Acme;

use GuzzleHttp\Psr7\Response;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Message\Message;
use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Athena\Turn;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Sse\SseStream;
use Phalanx\Stoa\Sse\SseStreamFactory;
use Phalanx\Task\Scopeable;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class TriageHandler implements Scopeable
{
    public function __construct(
        private readonly ProviderConfig $providers,
    ) {
    }

    public function __invoke(RequestScope $scope): ResponseInterface|SseStream
    {
        if (!array_key_exists('anthropic', $this->providers->all())) {
            return new Response(
                503,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'error' => 'Anthropic provider is not configured.',
                    'hint' => 'Run with ATHENA_DEMO_LIVE=1 and ANTHROPIC_API_KEY set.',
                ], JSON_THROW_ON_ERROR),
            );
        }

        $body = $scope->body->json();

        $turn = Turn::begin(new SupportTriageAgent())
            ->message(Message::user(
                "Ticket from: {$body['customer_email']}\n" .
                "Subject: {$body['subject']}\n\n" .
                $body['body']
            ))
            ->output(TriageResult::class)
            ->maxSteps(4);

        $stream = $this->openStream($scope);

        try {
            foreach (AgentLoop::run($turn, $scope)($scope) as $event) {
                if (!$event->kind->isUserFacing()) {
                    continue;
                }

                $stream->writeEvent(
                    json_encode(match ($event->kind) {
                    AgentEventKind::TokenDelta => [
                        'type' => 'token',
                        'text' => $event->data->text,
                    ],
                    AgentEventKind::ToolCallStart => [
                        'type' => 'tool_start',
                        'tool' => $event->data->toolName,
                    ],
                    AgentEventKind::ToolCallComplete => [
                        'type' => 'tool_done',
                        'ms' => $event->elapsed,
                        'tool' => $event->data->toolName,
                    ],
                    AgentEventKind::StructuredOutput => [
                        'type' => 'triage',
                        'priority' => $event->data->value->priority->value,
                        'category' => $event->data->value->category->value,
                        'auto_resolvable' => $event->data->value->autoResolvable,
                    ],
                    default => [
                        'type' => 'event',
                        'kind' => $event->kind->value,
                    ],
                }, JSON_THROW_ON_ERROR),
                    event: 'triage',
                );
            }
        } catch (Throwable $e) {
            $stream->writeEvent(
                json_encode([
                    'type' => 'error',
                    'message' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR),
                event: 'triage',
            );
        }

        $stream->close();

        return $stream;
    }

    private function openStream(RequestScope $scope): SseStream
    {
        return (new SseStreamFactory())->open($scope);
    }
}
