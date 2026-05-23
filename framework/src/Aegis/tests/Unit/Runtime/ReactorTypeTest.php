<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Runtime;

use Phalanx\Runtime\ReactorType;
use PHPUnit\Framework\TestCase;

/**
 * The reactor type is metadata; `serverConfigValue()` materializes it
 * into the shape OpenSwoole's `Server::set` consumes, with Auto returning
 * null so callers omit the key entirely.
 */
final class ReactorTypeTest extends TestCase
{
    public function testAutoReturnsNullForServerConfig(): void
    {
        self::assertNull(ReactorType::Auto->serverConfigValue());
    }

    public function testIoUringMaterializesAsString(): void
    {
        self::assertSame('io_uring', ReactorType::IoUring->serverConfigValue());
    }

    public function testIoUringRequiresLinuxAndMinKernel(): void
    {
        $reactor = ReactorType::IoUring;

        self::assertTrue($reactor->requiresLinux());
        self::assertSame('5.13', $reactor->minimumKernelVersion());
    }

    public function testKqueueDoesNotRequireLinux(): void
    {
        self::assertFalse(ReactorType::Kqueue->requiresLinux());
        self::assertNull(ReactorType::Kqueue->minimumKernelVersion());
    }

    public function testEpollRequiresLinuxButNoMinKernel(): void
    {
        self::assertTrue(ReactorType::Epoll->requiresLinux());
        self::assertNull(ReactorType::Epoll->minimumKernelVersion());
    }
}
