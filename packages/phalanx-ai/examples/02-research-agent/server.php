<?php

declare(strict_types=1);

/**
 * Research Agent Server
 *
 * Demonstrates a multi-document research agent with parallel analysis
 * and live progress via WebSocket. Users upload documents, ask a question,
 * and watch each document being processed concurrently.
 *
 * Usage:
 *   php server.php
 *
 * Architecture:
 *   - ExtractDocumentContent and QuerySpreadsheet each spawn sub-agents
 *   - SummarizationAgent and DataAnalyst run inside child scopes
 *   - All document extractions happen concurrently (time of slowest, not sum of all)
 *   - WebSocket event stream shows multi-stage progress visualization
 */

/*
 * In a real Phalanx app:
 *
 * $researchWs = new WsRoute(
 *     fn: static function (WsScope $scope): void {
 *         $conn = $scope->connection;
 *
 *         foreach ($conn->inbound->consume() as $msg) {
 *             if (!$msg->isText) continue;
 *
 *             $request = $msg->json();
 *             if ($request['type'] !== 'research') continue;
 *
 *             $documents = $request['documents'];
 *             $question = $request['question'];
 *
 *             $documentList = implode("\n", array_map(
 *                 fn($d) => "- {$d['name']} ({$d['type']}): {$d['path']}",
 *                 $documents
 *             ));
 *
 *             $turn = Turn::begin(new ResearchAgent())
 *                 ->message(Message::user(
 *                     "Documents:\n{$documentList}\n\n" .
 *                     "Research question: {$question}"
 *                 ))
 *                 ->maxSteps(8);
 *
 *             $events = AgentLoop::run($turn, $scope);
 *
 *             // Stream events to WebSocket with progress labels
 *             foreach ($events($scope) as $event) {
 *                 match ($event->kind) {
 *                     AgentEventKind::ToolCallStart => $conn->send(...),
 *                     AgentEventKind::ToolCallComplete => $conn->send(...),
 *                     AgentEventKind::TokenDelta => $conn->send(...),
 *                     AgentEventKind::AgentComplete => $conn->send(...),
 *                     default => null,
 *                 };
 *             }
 *         }
 *     },
 * );
 */

echo "Research Agent Example\n";
echo "======================\n\n";
echo "This example demonstrates a multi-document research agent.\n";
echo "In production, this runs as a Phalanx WebSocket server.\n\n";
echo "Agent: ResearchAgent (spawns SummarizationAgent, DataAnalyst sub-agents)\n";
echo "Tools: ExtractDocumentContent, QuerySpreadsheet, CrossReference\n";
echo "Transport: WebSocket (real-time progress)\n\n";
echo "Key architecture decisions:\n";
echo "  - Sub-agents keep the main context window lean\n";
echo "  - Concurrent tool execution: 5 docs in time of slowest, not sum\n";
echo "  - Each sub-agent has its own scope, timeout, and tool set\n";
echo "  - Parent cancellation tears down all children deterministically\n";
