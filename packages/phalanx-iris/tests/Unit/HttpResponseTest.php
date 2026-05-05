<?php

declare(strict_types=1);

namespace Phalanx\Iris\Tests\Unit;

use Phalanx\Iris\HttpResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpResponseTest extends TestCase
{
    #[Test]
    public function headersAreCaseInsensitiveAndMultiValue(): void
    {
        $response = new HttpResponse(
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
        $response = new HttpResponse(500, 'Internal Server Error', [], 'nope');

        self::assertFalse($response->successful);
    }
}
