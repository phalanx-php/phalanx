<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Substrate;

use Phalanx\Substrate\RuntimeHookFlags;
use PHPUnit\Framework\TestCase;

final class RuntimeHookFlagsTest extends TestCase
{
    public function testConstructorSetsAllFlags(): void
    {
        $flags = new RuntimeHookFlags(
            tcp: 2,
            udp: 4,
            unix: 8,
            ssl: 32,
            tls: 64,
            file: 256,
            sleep: 512,
            curl: 2048,
            blocking: 8192,
            all: 0x7FFFFFFF,
        );

        $this->assertSame(2, $flags->tcp);
        $this->assertSame(4, $flags->udp);
        $this->assertSame(8, $flags->unix);
        $this->assertSame(32, $flags->ssl);
        $this->assertSame(64, $flags->tls);
        $this->assertSame(256, $flags->file);
        $this->assertSame(512, $flags->sleep);
        $this->assertSame(2048, $flags->curl);
        $this->assertSame(8192, $flags->blocking);
        $this->assertSame(0x7FFFFFFF, $flags->all);
    }

    public function testFlagsAreReadonly(): void
    {
        $flags = new RuntimeHookFlags(
            tcp: 2,
            udp: 4,
            unix: 8,
            ssl: 32,
            tls: 64,
            file: 256,
            sleep: 512,
            curl: 2048,
            blocking: 8192,
            all: 0x7FFFFFFF,
        );

        $reflection = new \ReflectionClass($flags);
        $this->assertTrue($reflection->isReadOnly());
    }
}
