<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Http\Client;

use Phalanx\Stoa\Http\Client\StreamingHttpResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamingHttpResponseTest extends TestCase
{
    #[Test]
    public function chunksIterateInOrder(): void
    {
        $response = new StreamingHttpResponse(
            status: 200,
            reasonPhrase: 'OK',
            headers: ['Content-Type' => ['text/plain']],
            body: 'abcdefghij',
            chunkSize: 3,
        );

        $chunks = iterator_to_array($response->chunks(), false);

        self::assertSame(['abc', 'def', 'ghi', 'j'], $chunks);
    }

    #[Test]
    public function emptyBodyYieldsNoChunks(): void
    {
        $response = new StreamingHttpResponse(200, 'OK', [], '', 1024);

        self::assertSame([], iterator_to_array($response->chunks(), false));
    }

    #[Test]
    public function fullBodyExposesUnchunkedContent(): void
    {
        $response = new StreamingHttpResponse(200, 'OK', [], 'whole', 1);

        self::assertSame('whole', $response->fullBody());
    }
}
