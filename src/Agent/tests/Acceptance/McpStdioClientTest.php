<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Acceptance;

use Phalanx\Agent\Mcp\Client\StdioClient;
use Phalanx\Agent\Mcp\McpServer;
use Phalanx\Agent\Mcp\McpTool;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class McpStdioClientTest extends PhalanxTestCase
{
    #[Test]
    public function stdioClientConnectsToFixtureServerDiscoverToolsInvokesAndDisconnects(): void
    {
        $server = McpServer::stdio(
            'echo-server',
            'php ' . \dirname(__DIR__) . '/Fixtures/mcp-echo-server.php',
        );

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($server): mixed {
            $client = new StdioClient();
            $connection = $client->connect($scope, $server);

            $tools = $connection->tools($scope);

            self::assertNotEmpty($tools);
            self::assertInstanceOf(McpTool::class, $tools[0]);
            self::assertSame('echo_tool', $tools[0]->name);
            self::assertSame('echo-server', $tools[0]->serverName);

            $outcome = $connection->invoke($scope, 'echo_tool', ['message' => 'Leonidas']);

            $connection->disconnect($scope);

            return $outcome;
        });

        self::assertNotNull($result);

        $this->scope->expect->runtime()->clean();
    }
}
