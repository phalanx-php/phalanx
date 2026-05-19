<?php

/**
 * MCP stdio client — connect, discover, invoke.
 *
 * Spawns the bundled echo-server fixture as a child process over stdin/
 * stdout. StdioClient speaks MCP JSON-RPC 2.0: initialize, tools/list,
 * tools/call, shutdown. The demo verifies scope cleanup leaves no orphaned
 * ledger tasks; child process termination is covered by McpStdioClientTest
 * in the acceptance test suite.
 *
 * This demo exercises the AT-C.02 claims gate without a live LLM provider.
 * It only needs the Aegis runtime and PHP itself (no external services).
 *
 * Usage:
 *   php demos/athena/03-mcp-stdio/demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Athena\Mcp\Client\StdioClient;
use Phalanx\Athena\Mcp\McpServer;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

// DemoApp::boot() eagerly constructs the Aegis kernel, which requires
// OpenSwoole\Table. Guard before boot so a missing extension produces a
// clean cannotRun message rather than a fatal ClassNotFoundError.
if (!extension_loaded('openswoole')) {
    return DemoReport::demo(
        'Athena MCP stdio client',
        static function (DemoReport $report): void {
            $report->cannotRun(
                'openswoole extension required',
                'Run with: php -d extension=openswoole demos/athena/03-mcp-stdio/demo.php',
            );
        },
    );
}

return DemoApp::boot(
    'Athena MCP stdio client',
    static function (DemoApp $app, DemoReport $report): void {
        $serverScript = __DIR__ . '/../../..'
            . '/packages/phalanx-athena/tests/Fixtures/mcp-echo-server.php';

        $report->note('Topic: Themistocles, architect of the Athenian fleet, routing commands to captains');
        $report->note(sprintf('MCP server: %s', basename($serverScript)));

        $result = $app->run(Task::named(
            'demo.athena.mcp-stdio',
            static function (ExecutionScope $scope) use ($serverScript): array {
                // This demo focuses on the StdioClient transport contract;
                // production code resolves McpRegistry from the Athena bundle.
                $server = McpServer::stdio('echo-server', 'php ' . $serverScript);
                $client = new StdioClient();

                $connection = $client->connect($scope, $server);

                $tools = $connection->tools($scope);

                $outcome = $connection->invoke(
                    $scope,
                    'echo_tool',
                    ['message' => 'march through Thermopylae'],
                );

                $running = $connection->isRunning();
                $connection->disconnect($scope);

                return [
                    'tools'   => $tools,
                    'outcome' => $outcome,
                    'running' => $running,
                ];
            },
        ));

        $tools = $result['tools'];

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
            isset($tools[0]) && $tools[0]->serverName === 'echo-server',
        );

        $report->record(
            'process was alive before disconnect',
            $result['running'] === true,
        );

        /** @var \Phalanx\Athena\Effect\Outcome $outcome */
        $outcome = $result['outcome'];

        $report->record(
            'invoke returned no error',
            $outcome->error === null,
        );

        $report->record(
            'scope cleanup left no orphaned tasks',
            $app->ledger()->liveTaskCount() === 0,
        );
    },
);
