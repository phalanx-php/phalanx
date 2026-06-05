<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Tests\Unit;

use Phalanx\HttpClient\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    #[Test]
    public function headersAreCaseInsensitiveAndMultiValue(): void
    {
        $response = new \Phalanx\HttpClient\Response(
            status: 200,
            reasonPhrase: 'OK',
            headers: [
                'Content-Type' => ['application/json'],
                'Set-Cookie' => ['a=1', 'b=2'],
            ],
            body: '{"ok":true}',
        );

        self::assertTrue($response->successful);
        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('a=1', $response->header('set-cookie'));
        self::assertNull($response->header('x-missing'));
    }

    #[Test]
    public function nonTwoHundredResponsesAreNotSuccessful(): void
    {
        $response = new \Phalanx\HttpClient\Response(500, 'Internal Server Error', [], 'nope');

        self::assertFalse($response->successful);
    }
}
