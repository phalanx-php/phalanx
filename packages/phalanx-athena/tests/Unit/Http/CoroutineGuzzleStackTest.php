<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Http;

use Phalanx\Athena\Http\CoroutineGuzzleStack;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The helper is opt-in: callers must install `guzzlehttp/guzzle` and
 * `hyperf/guzzle` (both `suggest`s on this package). Without them, the
 * factory must fail with a message that names the missing dependency.
 *
 * In CI Phalanx ships neither package, so this test exercises the
 * not-installed branch. Live wiring is exercised by the demo when both
 * packages are present in the consuming application.
 */
final class CoroutineGuzzleStackTest extends TestCase
{
    public function testThrowsWhenGuzzleMissing(): void
    {
        if (class_exists(\GuzzleHttp\HandlerStack::class)) {
            self::markTestSkipped('guzzlehttp/guzzle is installed; this test asserts the missing-dep path.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('guzzlehttp/guzzle is not installed');

        CoroutineGuzzleStack::create();
    }

    public function testThrowsWhenHyperfGuzzleMissing(): void
    {
        if (!class_exists(\GuzzleHttp\HandlerStack::class)) {
            self::markTestSkipped('guzzlehttp/guzzle not installed; cannot reach the hyperf-missing branch.');
        }
        if (class_exists(\Hyperf\Guzzle\CoroutineHandler::class)) {
            self::markTestSkipped('hyperf/guzzle is installed; this test asserts the missing-dep path.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hyperf/guzzle is not installed');

        CoroutineGuzzleStack::create();
    }
}
