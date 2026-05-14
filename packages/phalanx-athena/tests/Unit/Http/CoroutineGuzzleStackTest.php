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
 */
final class CoroutineGuzzleStackTest extends TestCase
{
    public function testThrowsWhenGuzzleMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('guzzlehttp/guzzle is not installed');

        CoroutineGuzzleStack::ensureDependencies(static fn(string $class): bool => false);
    }

    public function testThrowsWhenHyperfGuzzleMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hyperf/guzzle is not installed');

        CoroutineGuzzleStack::ensureDependencies(
            static fn(string $class): bool => $class === \GuzzleHttp\HandlerStack::class,
        );
    }

    public function testCreatesCoroutineAwareHandlerStackWhenDependenciesAreInstalled(): void
    {
        $stack = CoroutineGuzzleStack::create();

        self::assertInstanceOf(\GuzzleHttp\HandlerStack::class, $stack);
    }
}
