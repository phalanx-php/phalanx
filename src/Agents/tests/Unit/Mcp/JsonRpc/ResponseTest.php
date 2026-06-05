<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Unit\Mcp\JsonRpc;

use Phalanx\Agents\Mcp\JsonRpc\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    #[Test]
    public function decodeSuccessResponseWithIntId(): void
    {
        $line = '{"jsonrpc":"2.0","id":1,"result":{"tools":[]}}' . "\n";
        $response = Response::decode($line);

        self::assertSame(1, $response->id);
        self::assertSame(['tools' => []], $response->result);
        self::assertNull($response->error);
        self::assertFalse($response->isError);
    }

    #[Test]
    public function decodeSuccessResponseWithStringId(): void
    {
        $line = '{"jsonrpc":"2.0","id":"req-99","result":null}';
        $response = Response::decode($line);

        self::assertSame('req-99', $response->id);
        self::assertNull($response->result);
        self::assertFalse($response->isError);
    }

    #[Test]
    public function decodeErrorResponseSetsErrorAndIsErrorTrue(): void
    {
        $line = '{"jsonrpc":"2.0","id":3,"error":{"code":-32601,"message":"Method not found"}}';
        $response = Response::decode($line);

        self::assertSame(3, $response->id);
        self::assertNull($response->result);
        self::assertSame(['code' => -32601, 'message' => 'Method not found'], $response->error);
        self::assertTrue($response->isError);
    }

    #[Test]
    public function isErrorPropertyHookReflectsErrorPresence(): void
    {
        $success = Response::decode('{"jsonrpc":"2.0","id":1,"result":"ok"}');
        $failure = Response::decode('{"jsonrpc":"2.0","id":2,"error":{"code":-1,"message":"oops"}}');

        self::assertFalse($success->isError);
        self::assertTrue($failure->isError);
    }

    #[Test]
    public function decodeMalformedJsonThrows(): void
    {
        $this->expectException(\JsonException::class);

        Response::decode('not valid json');
    }

    #[Test]
    public function decodeMissingJsonrpcVersionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing or incorrect jsonrpc version');

        Response::decode('{"id":1,"result":null}');
    }

    #[Test]
    public function decodeWrongJsonrpcVersionThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        Response::decode('{"jsonrpc":"1.0","id":1,"result":null}');
    }

    #[Test]
    public function decodeMissingIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing id');

        Response::decode('{"jsonrpc":"2.0","result":null}');
    }

    #[Test]
    public function decodeNonObjectJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an object');

        Response::decode('"just a string"');
    }
}
