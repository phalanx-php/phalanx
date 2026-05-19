<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Mcp;

use Phalanx\Athena\Effect\Outcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Mcp\McpConnection;
use Phalanx\Athena\Mcp\McpRegistry;
use Phalanx\Athena\Mcp\McpTool;
use Phalanx\Athena\Testing\ScopeStub;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpRegistryTest extends TestCase
{
    private McpConnection $connection;
    private McpTool $toolA;
    private McpTool $toolB;
    private Outcome $cannedOutcome;

    #[Test]
    public function registeredConnectionToolsAreDiscovered(): void
    {
        $registry = new McpRegistry();
        $registry->register(new ScopeStub(), $this->connection);

        self::assertCount(2, $registry->tools());
        self::assertContains($this->toolA, $registry->tools());
        self::assertContains($this->toolB, $registry->tools());
    }

    #[Test]
    public function findReturnsToolByName(): void
    {
        $registry = new McpRegistry();
        $registry->register(new ScopeStub(), $this->connection);

        $found = $registry->find('read_file');

        self::assertSame($this->toolA, $found);
    }

    #[Test]
    public function findReturnsNullForUnknownTool(): void
    {
        $registry = new McpRegistry();
        $registry->register(new ScopeStub(), $this->connection);

        self::assertNull($registry->find('nonexistent'));
    }

    #[Test]
    public function connectionLookupByServerName(): void
    {
        $registry = new McpRegistry();
        $registry->register(new ScopeStub(), $this->connection);

        self::assertSame($this->connection, $registry->connection('filesystem'));
        self::assertNull($registry->connection('unknown-server'));
    }

    #[Test]
    public function invokeDelegatesToOwningConnection(): void
    {
        $registry = new McpRegistry();
        $registry->register(new ScopeStub(), $this->connection);

        $scope = new ScopeStub();
        $result = $registry->invoke($scope, 'read_file', ['path' => '/tmp/test']);

        self::assertSame($this->cannedOutcome, $result);
    }

    #[Test]
    public function invokeThrowsForUnknownTool(): void
    {
        $registry = new McpRegistry();
        $registry->register(new ScopeStub(), $this->connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown MCP tool: phantom');

        $registry->invoke(new ScopeStub(), 'phantom', []);
    }

    #[Test]
    public function registerAcceptsMultipleConnections(): void
    {
        $registry = new McpRegistry();
        $registry->register(new ScopeStub(), $this->connection);

        self::assertCount(2, $registry->tools());
    }

    protected function setUp(): void
    {
        $this->toolA = new McpTool('read_file', 'Reads a file', ['type' => 'object'], 'filesystem');
        $this->toolB = new McpTool('list_dir', 'Lists a directory', ['type' => 'object'], 'filesystem');
        $this->cannedOutcome = Outcome::routed(Resolution::McpTool, data: ['content' => 'hello']);

        $tools = [$this->toolA, $this->toolB];
        $outcome = $this->cannedOutcome;

        $this->connection = new class ($tools, $outcome) implements McpConnection {
            /**
             * @param list<McpTool> $fixedTools
             */
            public function __construct(
                private array $fixedTools,
                private Outcome $fixedOutcome,
            ) {
            }

            /** @return list<McpTool> */
            public function tools(TaskScope $scope): array
            {
                return $this->fixedTools;
            }

            public function invoke(TaskScope $scope, string $toolName, array $args): Outcome
            {
                return $this->fixedOutcome;
            }

            public function isRunning(): bool
            {
                return true;
            }

            public function disconnect(TaskScope $scope): void
            {
            }
        };
    }
}
