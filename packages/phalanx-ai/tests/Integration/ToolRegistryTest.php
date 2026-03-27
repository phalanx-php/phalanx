<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tests\Integration;

use Phalanx\Ai\Tests\Fixtures\CalculatorTool;
use Phalanx\Ai\Tests\Fixtures\EchoTool;
use Phalanx\Ai\Tests\Fixtures\TestToolBundle;
use Phalanx\Ai\Tool\ToolCall;
use Phalanx\Ai\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function registers_individual_tools(): void
    {
        $registry = ToolRegistry::from([EchoTool::class]);

        $this->assertTrue($registry->has('echo_tool'));
        $this->assertFalse($registry->has('nonexistent'));
    }

    #[Test]
    public function registers_tool_bundles(): void
    {
        $registry = ToolRegistry::from([new TestToolBundle()]);

        $this->assertTrue($registry->has('echo_tool'));
        $this->assertTrue($registry->has('calculator_tool'));
    }

    #[Test]
    public function generates_all_schemas(): void
    {
        $registry = ToolRegistry::from([EchoTool::class, CalculatorTool::class]);

        $schemas = $registry->allSchemas();

        $this->assertCount(2, $schemas);
        $this->assertSame('echo_tool', $schemas[0]['name']);
        $this->assertSame('calculator_tool', $schemas[1]['name']);
    }

    #[Test]
    public function hydrates_tool_from_call(): void
    {
        $registry = ToolRegistry::from([EchoTool::class]);

        $call = new ToolCall(id: 'call_1', name: 'echo_tool', arguments: ['message' => 'hello']);
        $tool = $registry->hydrate($call);

        $this->assertInstanceOf(EchoTool::class, $tool);
    }

    #[Test]
    public function hydrate_throws_for_unknown_tool(): void
    {
        $registry = ToolRegistry::from([EchoTool::class]);
        $call = new ToolCall(id: 'call_1', name: 'nonexistent', arguments: []);

        $this->expectException(\InvalidArgumentException::class);
        $registry->hydrate($call);
    }

    #[Test]
    public function tool_call_from_array(): void
    {
        $call = ToolCall::fromArray([
            'id' => 'call_abc',
            'name' => 'echo_tool',
            'input' => ['message' => 'hello'],
        ]);

        $this->assertSame('call_abc', $call->id);
        $this->assertSame('echo_tool', $call->name);
        $this->assertSame(['message' => 'hello'], $call->arguments);
    }

    #[Test]
    public function tool_call_bag_operations(): void
    {
        $bag = new \Phalanx\Ai\Tool\ToolCallBag([
            new ToolCall('1', 'echo_tool', ['message' => 'a']),
            new ToolCall('2', 'calculator_tool', ['a' => 1, 'b' => 2]),
        ]);

        $this->assertFalse($bag->isEmpty());
        $this->assertSame(2, $bag->count());
        $this->assertSame('1', $bag->get(0)->id);

        $names = $bag->map(static fn(ToolCall $tc) => $tc->name);
        $this->assertSame(['echo_tool', 'calculator_tool'], $names);
    }

    #[Test]
    public function empty_tool_call_bag(): void
    {
        $bag = new \Phalanx\Ai\Tool\ToolCallBag([]);

        $this->assertTrue($bag->isEmpty());
        $this->assertSame(0, $bag->count());
    }
}
