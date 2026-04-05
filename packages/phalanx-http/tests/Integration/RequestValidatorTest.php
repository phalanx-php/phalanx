<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Integration;

use Phalanx\Http\RequestBody;
use Phalanx\Http\RequestValidator;
use Phalanx\Http\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

final class RequestValidatorTest extends TestCase
{
    #[Test]
    public function passing_validator_returns_value(): void
    {
        $body = $this->createBody('{"age":25}');
        $alwaysPass = $this->validator(static fn(mixed $v): bool => true);

        $this->assertSame(25, $body->int('age', validate: $alwaysPass));
    }

    #[Test]
    public function failing_validator_throws_validation_exception(): void
    {
        $body = $this->createBody('{"age":15}');
        $over18 = $this->validator(static fn(mixed $v): bool => $v >= 18);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Validation failed for field 'age'");

        $body->int('age', validate: $over18);
    }

    #[Test]
    public function validation_exception_carries_field_and_value(): void
    {
        $body = $this->createBody('{"name":"x"}');
        $minLength = $this->validator(static fn(mixed $v): bool => strlen((string) $v) >= 3);

        try {
            $body->string('name', validate: $minLength);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('name', $e->field);
            $this->assertSame('x', $e->value);
            $this->assertSame($minLength, $e->validator);
        }
    }

    #[Test]
    public function validation_is_cached_per_key_and_validator(): void
    {
        $body = $this->createBody('{"count":5}');
        $calls = 0;

        $counter = $this->validator(static function (mixed $v) use (&$calls): bool {
            $calls++;
            return true;
        });

        $body->int('count', validate: $counter);
        $body->int('count', validate: $counter);
        $body->int('count', validate: $counter);

        $this->assertSame(1, $calls);
    }

    #[Test]
    public function different_validators_on_same_key_both_run(): void
    {
        $body = $this->createBody('{"value":10}');
        $callsA = 0;
        $callsB = 0;

        $validatorA = $this->validator(static function (mixed $v) use (&$callsA): bool {
            $callsA++;
            return true;
        });

        $validatorB = $this->validator(static function (mixed $v) use (&$callsB): bool {
            $callsB++;
            return true;
        });

        $body->int('value', validate: $validatorA);
        $body->int('value', validate: $validatorB);

        $this->assertSame(1, $callsA);
        $this->assertSame(1, $callsB);
    }

    #[Test]
    public function null_value_skips_validation(): void
    {
        $body = $this->createBody('{}');
        $calls = 0;

        $validator = $this->validator(static function (mixed $v) use (&$calls): bool {
            $calls++;
            return true;
        });

        $result = $body->get('missing', validate: $validator);

        $this->assertNull($result);
        $this->assertSame(0, $calls);
    }

    #[Test]
    public function required_with_validator(): void
    {
        $body = $this->createBody('{"email":"test@example.com"}');
        $hasAt = $this->validator(static fn(mixed $v): bool => str_contains((string) $v, '@'));

        $this->assertSame('test@example.com', $body->required('email', validate: $hasAt));
    }

    #[Test]
    public function required_with_failing_validator(): void
    {
        $body = $this->createBody('{"email":"invalid"}');
        $hasAt = $this->validator(static fn(mixed $v): bool => str_contains((string) $v, '@'));

        $this->expectException(ValidationException::class);
        $body->required('email', validate: $hasAt);
    }

    #[Test]
    public function bool_with_validator(): void
    {
        $body = $this->createBody('{"active":"true"}');
        $mustBeTrue = $this->validator(static fn(mixed $v): bool => $v === true);

        $this->assertTrue($body->bool('active', validate: $mustBeTrue));
    }

    private function createBody(string $content): RequestBody
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($content);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);

        return RequestBody::from($request);
    }

    private function validator(\Closure $fn): RequestValidator
    {
        return new readonly class ($fn) implements RequestValidator {
            public function __construct(private \Closure $fn)
            {
            }

            public function __invoke(mixed $value): bool
            {
                return ($this->fn)($value);
            }
        };
    }
}
