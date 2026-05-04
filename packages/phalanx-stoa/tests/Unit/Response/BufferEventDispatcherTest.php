<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Response;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Stoa\Response\BufferEventDispatcher;
use Phalanx\Stoa\StoaRequestResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BufferEventDispatcherTest extends TestCase
{
    #[Test]
    public function tracksAndUntracksRequestsByFd(): void
    {
        $dispatcher = new BufferEventDispatcher();
        $resource = self::makeRequest(42);

        self::assertFalse($dispatcher->tracksFd(42));

        $dispatcher->track(42, $resource);
        self::assertTrue($dispatcher->tracksFd(42));

        $dispatcher->untrack(42);
        self::assertFalse($dispatcher->tracksFd(42));
    }

    #[Test]
    public function multipleFdsIsolatedFromEachOther(): void
    {
        $dispatcher = new BufferEventDispatcher();
        $r1 = self::makeRequest(1);
        $r2 = self::makeRequest(2);

        $dispatcher->track(1, $r1);
        $dispatcher->track(2, $r2);

        self::assertTrue($dispatcher->tracksFd(1));
        self::assertTrue($dispatcher->tracksFd(2));

        $dispatcher->untrack(1);
        self::assertFalse($dispatcher->tracksFd(1));
        self::assertTrue($dispatcher->tracksFd(2));
    }

    private static function makeRequest(int $fd): StoaRequestResource
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());
        $context = new RuntimeContext($memory);

        return StoaRequestResource::open(
            runtime: $context,
            request: new ServerRequest('GET', '/test'),
            token: CancellationToken::none(),
            fd: $fd,
        );
    }
}
