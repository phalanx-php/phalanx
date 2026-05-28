<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\ReplAgent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReplAgentTest extends TestCase
{
    #[Test]
    public function tool_schemas_returns_two_entries(): void
    {
        $agent = new ReplAgent();

        self::assertCount(2, $agent->toolSchemas());
    }

    #[Test]
    public function tool_schemas_have_ollama_format(): void
    {
        $agent = new ReplAgent();

        foreach ($agent->toolSchemas() as $schema) {
            self::assertSame('function', $schema['type']);
            self::assertArrayHasKey('function', $schema);
            self::assertArrayHasKey('name', $schema['function']);
            self::assertArrayHasKey('description', $schema['function']);
            self::assertArrayHasKey('parameters', $schema['function']);
        }
    }

    #[Test]
    public function execute_tool_returns_array_for_known_tools(): void
    {
        $agent = new ReplAgent();

        $result = $agent->executeTool('consult_oracle', ['question' => 'Should we advance?']);

        self::assertIsArray($result);
        self::assertArrayHasKey('prophecy', $result);
    }

    #[Test]
    public function execute_tool_returns_null_for_unknown_tool(): void
    {
        $agent = new ReplAgent();

        self::assertNull($agent->executeTool('unknown_tool', []));
    }

    #[Test]
    public function instructions_mention_tools(): void
    {
        $agent = new ReplAgent();

        self::assertStringContainsString('consult_oracle', $agent->instructions);
        self::assertStringContainsString('inspect_terrain', $agent->instructions);
    }
}
