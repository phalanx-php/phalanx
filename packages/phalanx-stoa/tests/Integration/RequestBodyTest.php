<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use Phalanx\Stoa\RequestBody;
use Phalanx\Stoa\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

final class RequestBodyTest extends TestCase
{
    #[Test]
    public function parses_json_body(): void
    {
        $body = $this->createBody('{"name":"Phalanx","version":2}');

        $this->assertSame(['name' => 'Phalanx', 'version' => 2], $body->json());
        $this->assertSame('Phalanx', $body->get('name'));
        $this->assertSame(2, $body->get('version'));
    }

    #[Test]
    public function json_throws_on_invalid_json(): void
    {
        $body = $this->createBody('not-json');

        $this->expectException(\JsonException::class);
        $body->json();
    }

    #[Test]
    public function typed_accessors_return_correct_types(): void
    {
        $body = $this->createBody('{"count":"42","active":"true","label":"hello"}');

        $this->assertSame(42, $body->int('count'));
        $this->assertTrue($body->bool('active'));
        $this->assertSame('hello', $body->string('label'));
    }

    #[Test]
    public function typed_accessors_return_defaults_for_missing_keys(): void
    {
        $body = $this->createBody('{}');

        $this->assertNull($body->int('missing'));
        $this->assertSame(99, $body->int('missing', 99));
        $this->assertFalse($body->bool('missing'));
        $this->assertTrue($body->bool('missing', true));
        $this->assertSame('', $body->string('missing'));
        $this->assertSame('fallback', $body->string('missing', 'fallback'));
    }

    #[Test]
    public function has_checks_key_existence(): void
    {
        $body = $this->createBody('{"present":null}');

        $this->assertTrue($body->has('present'));
        $this->assertFalse($body->has('absent'));
    }

    #[Test]
    public function required_throws_on_missing_key(): void
    {
        $body = $this->createBody('{"a":1}');

        $this->assertSame(1, $body->required('a'));

        try {
            $body->required('b');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('b', $e->errors);
            $this->assertSame(['Missing required body parameter: b'], $e->errors['b']);
        }
    }

    #[Test]
    public function all_returns_full_parsed_body(): void
    {
        $data = ['x' => 1, 'y' => 2];
        $body = $this->createBody(json_encode($data, JSON_THROW_ON_ERROR));

        $this->assertSame($data, $body->all());
    }

    #[Test]
    public function text_returns_raw_body(): void
    {
        $raw = '{"key":"value"}';
        $body = $this->createBody($raw);

        $this->assertSame($raw, $body->text());
    }

    #[Test]
    public function empty_body_returns_empty_values(): void
    {
        $body = $this->createBody('');

        $this->assertSame([], $body->all());
        $this->assertNull($body->get('any'));
        $this->assertFalse($body->has('any'));
        $this->assertSame('', $body->text());
    }

    #[Test]
    public function non_json_body_returns_empty_values(): void
    {
        $body = $this->createBody('plain text content');

        $this->assertSame([], $body->all());
        $this->assertNull($body->get('any'));
        $this->assertSame('plain text content', $body->text());
    }

    #[Test]
    public function scalar_json_body_returns_empty_values(): void
    {
        $body = $this->createBody('"just a string"');

        $this->assertSame([], $body->all());
        $this->assertSame('just a string', $body->json());
    }

    private function createBody(string $content): RequestBody
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($content);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);

        return RequestBody::from($request);
    }
}
