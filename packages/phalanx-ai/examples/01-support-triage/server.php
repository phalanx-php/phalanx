<?php

declare(strict_types=1);

/**
 * Support Triage Server
 *
 * Demonstrates a streaming SSE endpoint that triages support tickets.
 * The agent classifies, gathers context, and drafts a response -- all
 * streamed to the support agent's browser in real time.
 *
 * Usage:
 *   php server.php
 *
 * Then POST to http://localhost:8080/triage with:
 *   {"ticket_id": 123, "customer_email": "sarah@example.com", "subject": "Export failing", "body": "..."}
 */

use Phalanx\Ai\AiServiceBundle;
use Phalanx\Ai\Event\AgentEvent;
use Phalanx\Ai\Event\AgentEventKind;
use Phalanx\Ai\Examples\SupportTriage\SupportTriageAgent;
use Phalanx\Ai\Examples\SupportTriage\TriageResult;
use Phalanx\Ai\Message\Message;
use Phalanx\Ai\Stream\TokenAccumulator;
use Phalanx\Ai\Turn;

/*
 * This example shows the HTTP handler structure. In a real Phalanx app,
 * this would be a Route in a RouteGroup, served by the ReactPHP runner.
 *
 * $triageRoute = new Route(
 *     fn: static function (RequestScope $scope) {
 *         $body = json_decode((string) $scope->request->getBody(), true);
 *
 *         $turn = Turn::begin(new SupportTriageAgent())
 *             ->message(Message::user(
 *                 "Ticket from: {$body['customer_email']}\n" .
 *                 "Subject: {$body['subject']}\n\n" .
 *                 $body['body']
 *             ))
 *             ->output(TriageResult::class)
 *             ->maxSteps(4);
 *
 *         $events = AgentLoop::run($turn, $scope);
 *         $accumulator = TokenAccumulator::from($events, $scope);
 *
 *         // Persist triage result after SSE stream completes
 *         $scope->onDispose(function () use ($accumulator, $body, $scope) {
 *             $result = $accumulator->result();
 *             if ($result->structured !== null) {
 *                 // Save to database
 *             }
 *         });
 *
 *         return SseResponse::from(
 *             $accumulator->events()
 *                 ->filter(fn($e) => $e->kind->isUserFacing())
 *                 ->map(fn($e) => json_encode(match ($e->kind) {
 *                     AgentEventKind::TokenDelta => [
 *                         'type' => 'token',
 *                         'text' => $e->data->text,
 *                     ],
 *                     AgentEventKind::ToolCallStart => [
 *                         'type' => 'tool_start',
 *                         'tool' => $e->data->toolName,
 *                     ],
 *                     AgentEventKind::ToolCallComplete => [
 *                         'type' => 'tool_done',
 *                         'tool' => $e->data->toolName,
 *                         'ms' => $e->elapsed,
 *                     ],
 *                     AgentEventKind::StructuredOutput => [
 *                         'type' => 'triage',
 *                         'priority' => $e->data->value->priority->value,
 *                         'category' => $e->data->value->category->value,
 *                         'auto_resolvable' => $e->data->value->autoResolvable,
 *                     ],
 *                     default => ['type' => 'event', 'kind' => $e->kind->value],
 *                 })),
 *             $scope,
 *             event: 'triage',
 *         );
 *     },
 * );
 */

echo "Support Triage Example\n";
echo "======================\n\n";
echo "This example demonstrates the Phalanx AI support triage agent.\n";
echo "In production, this runs as a Phalanx HTTP server with SSE streaming.\n\n";
echo "Agent: SupportTriageAgent\n";
echo "Tools: LookupCustomer, SearchKnowledgeBase, GetRecentTickets, CheckServiceStatus\n";
echo "Output: TriageResult (structured)\n";
echo "Transport: SSE (Server-Sent Events)\n\n";
echo "The agent receives a ticket, concurrently classifies it AND gathers\n";
echo "customer context, then drafts a response -- all streaming to the\n";
echo "support agent's browser in real time.\n";
