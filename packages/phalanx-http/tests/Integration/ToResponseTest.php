<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Integration;

use Phalanx\Http\Runner;
use Phalanx\Http\ToResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class ToResponseTest extends TestCase
{
    #[Test]
    public function to_response_returns_response_interface_unchanged(): void
    {
        $response = new Response(200, [], 'ok');
        $result = Runner::toResponse($response);

        $this->assertSame($response, $result);
    }

    #[Test]
    public function to_response_calls_to_response_on_implementor(): void
    {
        $inner = new Response(201, ['X-Custom' => 'yes'], '{"created":true}');

        $obj = new class ($inner) implements ToResponse {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function toResponse(): ResponseInterface
            {
                return $this->response;
            }
        };

        $result = Runner::toResponse($obj);

        $this->assertSame($inner, $result);
        $this->assertSame(201, $result->getStatusCode());
        $this->assertSame('yes', $result->getHeaderLine('X-Custom'));
    }

    #[Test]
    public function to_response_converts_array_to_json(): void
    {
        $result = Runner::toResponse(['key' => 'value']);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('application/json', $result->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function to_response_converts_string_to_plain_text(): void
    {
        $result = Runner::toResponse('hello world');

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('text/plain', $result->getHeaderLine('Content-Type'));
        $this->assertSame('hello world', (string) $result->getBody());
    }
}
