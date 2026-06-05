<?php

/**
 * MCP SSE client — connect, discover, invoke.
 *
 * Exercises SseClient through a deterministic in-process SSE transcript.
 * FakeHttpClient replays a pre-constructed byte sequence (the SSE endpoint
 * event, then JSON-RPC message events) so the demo runs without an external
 * service or network dependency.
 *
 * The transcript exercises the full MCP protocol path: initialize,
 * notifications/initialized, tools/list, tools/call, shutdown — all over
 * the two-endpoint SSE transport described in the 2024-11-05 MCP spec.
 *
 * This demo exercises the AT-C.03 claims gate.
 *
 * Usage:
 *   php demos/agent/04-mcp-sse/demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Agent\Mcp\Client\SseClient;
use Phalanx\Agent\Mcp\McpServer;
use Phalanx\Agent\Testing\FakeHttpClient;
use Phalanx\Agent\Testing\FakeHttpStream;
use Phalanx\Agent\Testing\ScopeStub;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\HttpClient\HttpResponse;

return DemoReport::demo(
    'Agent MCP SSE client',
    static function (DemoReport $report, AppContext $_context): void {
        $report->note('Topic: Pericles, statesman of Athens, dispatching envoys across the agora');

        // Build the SSE byte sequence that a real MCP-over-SSE server would
        // produce on the GET /sse connection. Framing follows the 2024-11-05
        // MCP spec: one "endpoint" event to advertise the POST URL, then one
        // "message" event per JSON-RPC response.
        $initResult = json_encode([
            'jsonrpc' => '2.0', 'id' => 1,
            'result'  => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => ['tools' => []],
                'serverInfo'      => ['name' => 'sse-echo-server', 'version' => '1.0'],
            ],
        ], JSON_THROW_ON_ERROR);

        $toolsResult = json_encode([
            'jsonrpc' => '2.0', 'id' => 2,
            'result'  => [
                'tools' => [[
                    'name'        => 'echo_tool',
                    'description' => 'Echoes input over SSE transport',
                    'inputSchema' => [
                        'type'       => 'object',
                        'properties' => ['message' => ['type' => 'string']],
                        'required'   => ['message'],
                    ],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $invokeResult = json_encode([
            'jsonrpc' => '2.0', 'id' => 3,
            'result'  => [
                'content' => [['type' => 'text', 'text' => 'Echo: march through the agora']],
                'isError' => false,
            ],
        ], JSON_THROW_ON_ERROR);

        $shutdownResult = json_encode([
            'jsonrpc' => '2.0', 'id' => 4,
            'result'  => null,
        ], JSON_THROW_ON_ERROR);

        // SSE stream body: endpoint event + four message events (one per
        // JSON-RPC response that will be consumed by tools() and invoke()).
        $sseBody = "event: endpoint\ndata: http://localhost/mcp/post\n\n"
            . "event: message\ndata: {$initResult}\n\n"
            . "event: message\ndata: {$toolsResult}\n\n"
            . "event: message\ndata: {$invokeResult}\n\n"
            . "event: message\ndata: {$shutdownResult}\n\n";

        $sseStream = new FakeHttpStream($sseBody);
        $httpClient = new FakeHttpClient($sseStream);
        $scope = new ScopeStub();

        // POST responses from the server are 202 Accepted (the response body
        // arrives via the SSE stream, not the POST response body).
        $httpClient->queuePostResponse(new HttpResponse(202, 'Accepted', [], ''));
        $httpClient->queuePostResponse(new HttpResponse(202, 'Accepted', [], ''));
        $httpClient->queuePostResponse(new HttpResponse(202, 'Accepted', [], ''));
        $httpClient->queuePostResponse(new HttpResponse(202, 'Accepted', [], ''));
        $httpClient->queuePostResponse(new HttpResponse(202, 'Accepted', [], ''));

        $client = new SseClient($httpClient);
        $server = McpServer::sse('sse-echo-server', 'http://localhost/sse');
        $connection = $client->connect($scope, $server);

        $tools = $connection->tools($scope);

        $report->record(
            'server returned at least one tool',
            count($tools) >= 1,
        );

        $report->record(
            'first tool is echo_tool',
            isset($tools[0]) && $tools[0]->name === 'echo_tool',
        );

        $report->record(
            'tool carries server name',
            isset($tools[0]) && $tools[0]->serverName === 'sse-echo-server',
        );

        $outcome = $connection->invoke(
            $scope,
            'echo_tool',
            ['message' => 'march through the agora'],
        );

        /** @var array{content: list<array{type: string, text: string}>, isError: bool}|null $data */
        $data = $outcome->data;
        $echoText = $data['content'][0]['text'] ?? '';

        $report->record(
            'invoke returned routed outcome',
            $outcome->error === null && $outcome->halt === false,
        );

        $report->record(
            'invoke payload round-tripped correctly',
            $echoText === 'Echo: march through the agora',
        );

        $connection->disconnect($scope);

        // Scope disposal must close the SSE stream.
        $scope->dispose();

        $report->record(
            'scope disposal closed the SSE stream',
            $sseStream->closeCalled,
        );

        $report->record(
            'client sent the expected POST requests',
            count($httpClient->postedRequests()) === 5,
        );
    },
);
