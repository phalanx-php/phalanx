<?php

/**
 * MCP stdio client — connect, discover, invoke via McpRegistry.
 *
 * Boots Aegis with AthenaBundle pre-configured with the echo-server
 * descriptor. Connects via StdioClient inside the task body and registers
 * the connection with McpRegistry, then invokes the tool through the
 * registry surface. This mirrors the production wiring path: the server
 * descriptor lives in the bundle, and registry access flows through
 * Athena::mcp($scope).
 *
 * StdioClient speaks MCP JSON-RPC 2.0: initialize, tools/list, tools/call,
 * shutdown. Child process termination is covered by McpStdioClientTest in
 * the acceptance test suite.
 *
 * This demo exercises the AT-C.02 claims gate without a live LLM provider.
 * It only needs the Aegis runtime and PHP itself (no external services).
 *
 * Usage:
 *   php -d extension=openswoole demos/athena/03-mcp-stdio/demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Athena\Athena;
use Phalanx\Athena\Mcp\Client\StdioClient;
use Phalanx\Athena\Mcp\McpServer;
use Phalanx\Athena\Router\SingleProviderRouter;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoProvider;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return static function (array $_context): Closure {
    // DemoApp::boot() eagerly constructs the Aegis kernel, which requires
    // OpenSwoole\Table. Guard before boot so a missing extension produces a
    // clean cannotRun message rather than a fatal ClassNotFoundError.
    if (!extension_loaded('openswoole')) {
        $inner = DemoReport::demo(
            'Athena MCP stdio client',
            static function (DemoReport $report): void {
                $report->cannotRun(
                    'openswoole extension required',
                    'Run with: php -d extension=openswoole demos/athena/03-mcp-stdio/demo.php',
                );
            },
        );

        return ($inner)([]);
    }

    $serverScript = __DIR__ . '/../../..'
        . '/framework/src/Athena/tests/Fixtures/mcp-echo-server.php';

    // Register the echo-server descriptor in the bundle so McpRegistry
    // knows about it before the task body runs. StdioClient still handles
    // the actual process spawn — the registry owns the logical view.
    $choice = DemoProvider::fakeOnly([]);
    $bundle = Athena::services(
        router: new SingleProviderRouter($choice->provider),
        mcpServers: [McpServer::stdio('echo-server', 'php ' . $serverScript)],
    );

    $bootClosure = DemoApp::boot(
        'Athena MCP stdio client',
        static function (DemoApp $app, DemoReport $report, AppContext $_ctx) use ($serverScript): void {
            $report->note('Topic: Themistocles, architect of the Athenian fleet, routing commands through McpRegistry');
            $report->note(sprintf('MCP server: %s', basename($serverScript)));

            $result = $app->run(Task::named(
                'demo.athena.mcp-stdio',
                static function (ExecutionScope $scope): array {
                    // Resolve the registry pre-populated from the bundle's
                    // mcpServers descriptor. Connect each pending server and
                    // register the live connection so the registry can route
                    // tool invocations.
                    $registry = Athena::mcp($scope);
                    $client   = new StdioClient();

                    foreach ($registry->pendingServers() as $server) {
                        $connection = $client->connect($scope, $server);
                        $registry->register($scope, $connection);
                    }

                    $tools = $registry->tools();

                    $outcome = $registry->invoke(
                        $scope,
                        'echo_tool',
                        ['message' => 'march through Thermopylae'],
                    );

                    $running = ($registry->connection('echo-server'))?->isRunning() ?? false;
                    $registry->connection('echo-server')?->disconnect($scope);

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
        [$bundle],
    );

    return ($bootClosure)([]);
};
