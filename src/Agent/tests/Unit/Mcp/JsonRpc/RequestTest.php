<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Unit\Mcp\JsonRpc;

use Phalanx\Agent\Mcp\JsonRpc\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    #[Test]
    public function encodeProducesValidJsonRpc20WithIntId(): void
    {
        $request = new Request(1, 'tools/list');
        $encoded = $request->encode();

        self::assertStringEndsWith("\n", $encoded);

        $decoded = json_decode(trim($encoded), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame(1, $decoded['id']);
        self::assertSame('tools/list', $decoded['method']);
        self::assertSame([], $decoded['params']);
    }

    #[Test]
    public function encodeProducesValidJsonRpc20WithStringId(): void
    {
        $request = new Request('req-42', 'initialize', ['protocolVersion' => '2024-11-05']);
        $encoded = $request->encode();

        $decoded = json_decode(trim($encoded), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame('req-42', $decoded['id']);
        self::assertSame('initialize', $decoded['method']);
        self::assertSame(['protocolVersion' => '2024-11-05'], $decoded['params']);
    }

    #[Test]
    public function encodeIncludesParamsWhenProvided(): void
    {
        $params = ['name' => 'echo_tool', 'arguments' => ['message' => 'hello']];
        $request = new Request(7, 'tools/call', $params);

        $decoded = json_decode(trim($request->encode()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($params, $decoded['params']);
    }

    #[Test]
    public function encodeAlwaysTerminatesWithNewline(): void
    {
        $request = new Request(0, 'ping');

        self::assertStringEndsWith("\n", $request->encode());
    }
}
