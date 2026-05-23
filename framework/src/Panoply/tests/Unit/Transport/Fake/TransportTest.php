<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Transport\Fake;

use Phalanx\Panoply\Runtime\CancellationException;
use Phalanx\Panoply\Runtime\Sync\Runtime;
use Phalanx\Panoply\Transport\Fake\Transport;
use Phalanx\Panoply\Transport\Fake\UnscriptedRequest;
use Phalanx\Panoply\Transport\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    #[Test]
    public function scriptedChunksYieldInOrder(): void
    {
        $transport = new Transport([
            'POST https://api.olympus.example/v1/messages' => ['chunk-alpha', 'chunk-beta', 'chunk-gamma'],
        ]);

        $chunks = [];
        foreach ($transport->stream(self::request(), new Runtime()) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertSame(['chunk-alpha', 'chunk-beta', 'chunk-gamma'], $chunks);
    }

    #[Test]
    public function requestsAreRecordedInOrder(): void
    {
        $transport = new Transport([
            'POST https://api.olympus.example/v1/messages' => ['data'],
        ]);

        $req = self::request();
        iterator_to_array($transport->stream($req, new Runtime()));
        iterator_to_array($transport->stream($req, new Runtime()));

        self::assertCount(2, $transport->requests());
        self::assertSame($req->url, $transport->requests()[0]->url);
        self::assertSame($req->url, $transport->requests()[1]->url);
    }

    #[Test]
    public function unscriptedRequestThrows(): void
    {
        $transport = new Transport([]);

        $this->expectException(UnscriptedRequest::class);

        iterator_to_array($transport->stream(self::request(), new Runtime()));
    }

    #[Test]
    public function cancellationIsHonoredBetweenChunks(): void
    {
        $runtime = new Runtime();
        $transport = new Transport([
            'POST https://api.olympus.example/v1/messages' => ['before', 'after'],
        ]);

        $chunks = [];

        $this->expectException(CancellationException::class);

        foreach ($transport->stream(self::request(), $runtime) as $chunk) {
            $chunks[] = $chunk;
            $runtime->cancel();
        }
    }

    #[Test]
    public function emptyScriptYieldsNoChunks(): void
    {
        $transport = new Transport([
            'POST https://api.olympus.example/v1/messages' => [],
        ]);

        $chunks = iterator_to_array($transport->stream(self::request(), new Runtime()));

        self::assertSame([], $chunks);
    }

    #[Test]
    public function requestsStartsEmpty(): void
    {
        $transport = new Transport([]);

        self::assertSame([], $transport->requests());
    }

    private static function request(): Request
    {
        return Request::of(
            method: 'POST',
            url: 'https://api.olympus.example/v1/messages',
            headers: ['Content-Type' => 'application/json'],
            body: '{"model":"leonidas-01","messages":[]}',
        );
    }
}
