<?php

declare(strict_types=1);

namespace Convoy\Parallel\Tests\Unit\Protocol;

use Convoy\Parallel\Protocol\Codec;
use Convoy\Parallel\Protocol\InvalidMessageException;
use Convoy\Parallel\Protocol\MessageType;
use Convoy\Parallel\Protocol\Response;
use Convoy\Parallel\Protocol\ServiceCall;
use Convoy\Parallel\Protocol\TaskRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodecTest extends TestCase
{
    #[Test]
    public function encodes_task_request_with_newline(): void
    {
        $request = new TaskRequest(
            id: 'abc123',
            taskClass: 'App\\Task\\FetchUser',
            constructorArgs: ['id' => 42],
            contextAttrs: ['user_id' => 1],
        );

        $encoded = Codec::encode($request);

        $this->assertStringEndsWith("\n", $encoded);
        $this->assertStringContainsString('"type":"task"', $encoded);
        $this->assertStringContainsString('"id":"abc123"', $encoded);
    }

    #[Test]
    public function decodes_task_request_from_json(): void
    {
        $json = '{"type":"task","id":"abc123","task":"App\\\\Task\\\\FetchUser","args":{"id":42},"context":{"user_id":1}}';

        $decoded = Codec::decode($json);

        $this->assertInstanceOf(TaskRequest::class, $decoded);
        $this->assertSame('abc123', $decoded->id);
        $this->assertSame('App\\Task\\FetchUser', $decoded->taskClass);
        $this->assertSame(['id' => 42], $decoded->constructorArgs);
        $this->assertSame(['user_id' => 1], $decoded->contextAttrs);
    }

    #[Test]
    public function encodes_and_decodes_task_request_roundtrip(): void
    {
        $original = new TaskRequest(
            id: 'test-123',
            taskClass: 'App\\Task\\ProcessImage',
            constructorArgs: ['path' => '/tmp/test.jpg', 'width' => 800],
            contextAttrs: ['request_id' => 'req-456'],
        );

        $encoded = Codec::encode($original);
        $decoded = Codec::decodeTaskRequest($encoded);

        $this->assertSame($original->id, $decoded->id);
        $this->assertSame($original->taskClass, $decoded->taskClass);
        $this->assertSame($original->constructorArgs, $decoded->constructorArgs);
        $this->assertSame($original->contextAttrs, $decoded->contextAttrs);
    }

    #[Test]
    public function encodes_service_call(): void
    {
        $call = new ServiceCall(
            id: 'call-123',
            serviceClass: 'App\\Service\\Database',
            method: 'query',
            args: ['SELECT * FROM users'],
        );

        $encoded = Codec::encode($call);

        $this->assertStringContainsString('"type":"service_call"', $encoded);
        $this->assertStringContainsString('"method":"query"', $encoded);
    }

    #[Test]
    public function decodes_service_call(): void
    {
        $json = '{"type":"service_call","id":"call-123","service":"App\\\\Service\\\\Db","method":"query","args":["SELECT 1"]}';

        $decoded = Codec::decodeServiceCall($json);

        $this->assertSame('call-123', $decoded->id);
        $this->assertSame('App\\Service\\Db', $decoded->serviceClass);
        $this->assertSame('query', $decoded->method);
        $this->assertSame(['SELECT 1'], $decoded->args);
    }

    #[Test]
    public function encodes_ok_response(): void
    {
        $response = Response::taskOk('req-123', ['data' => 'result']);

        $encoded = Codec::encode($response);

        $this->assertStringContainsString('"type":"task_response"', $encoded);
        $this->assertStringContainsString('"ok":true', $encoded);
        $this->assertStringContainsString('"result":', $encoded);
    }

    #[Test]
    public function encodes_error_response(): void
    {
        $exception = new \RuntimeException('Something went wrong');
        $response = Response::taskErr('req-123', $exception);

        $encoded = Codec::encode($response);

        $this->assertStringContainsString('"type":"task_response"', $encoded);
        $this->assertStringContainsString('"ok":false', $encoded);
        $this->assertStringContainsString('"error":"RuntimeException"', $encoded);
        $this->assertStringContainsString('"message":"Something went wrong"', $encoded);
    }

    #[Test]
    public function decodes_ok_response(): void
    {
        $json = '{"type":"task_response","id":"req-123","ok":true,"result":"success"}';

        $decoded = Codec::decodeResponse($json);

        $this->assertTrue($decoded->ok);
        $this->assertSame('success', $decoded->result);
        $this->assertSame('success', $decoded->unwrap());
    }

    #[Test]
    public function decodes_error_response(): void
    {
        $json = '{"type":"task_response","id":"req-123","ok":false,"error":"RuntimeException","message":"Failed","trace":"..."}';

        $decoded = Codec::decodeResponse($json);

        $this->assertFalse($decoded->ok);
        $this->assertSame('RuntimeException', $decoded->errorClass);
        $this->assertSame('Failed', $decoded->errorMessage);
    }

    #[Test]
    public function unwrap_throws_on_error_response(): void
    {
        $json = '{"type":"task_response","id":"req-123","ok":false,"error":"RuntimeException","message":"Task failed"}';

        $decoded = Codec::decodeResponse($json);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task failed');

        $decoded->unwrap();
    }

    #[Test]
    public function throws_on_missing_type(): void
    {
        $json = '{"id":"req-123","ok":true}';

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Missing type field');

        Codec::decode($json);
    }

    #[Test]
    public function throws_on_unknown_type(): void
    {
        $json = '{"type":"unknown","id":"req-123"}';

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Unknown message type');

        Codec::decode($json);
    }

    #[Test]
    public function throws_on_invalid_json(): void
    {
        $this->expectException(\JsonException::class);

        Codec::decode('not valid json');
    }

    #[Test]
    public function decodes_service_response(): void
    {
        $json = '{"type":"service_response","id":"svc-123","ok":true,"result":{"rows":3}}';

        $decoded = Codec::decodeResponse($json);

        $this->assertSame(MessageType::ServiceResponse, $decoded->type);
        $this->assertTrue($decoded->ok);
        $this->assertSame(['rows' => 3], $decoded->result);
    }

    #[Test]
    public function handles_whitespace_in_line(): void
    {
        $json = "  {\"type\":\"task_response\",\"id\":\"req-123\",\"ok\":true,\"result\":null}  \n";

        $decoded = Codec::decodeResponse($json);

        $this->assertTrue($decoded->ok);
        $this->assertNull($decoded->result);
    }
}
