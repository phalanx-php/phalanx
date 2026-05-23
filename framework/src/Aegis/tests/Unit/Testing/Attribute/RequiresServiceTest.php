<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Attribute;

use Phalanx\Testing\Attribute\RequiresDaemon8;
use Phalanx\Testing\Attribute\RequiresService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequiresServiceTest extends TestCase
{
    #[Test]
    public function unavailable_service_returns_false(): void
    {
        $attr = new RequiresService('test', port: 19999, timeout: 0.1);

        $this->assertFalse($attr->isAvailable());
    }

    #[Test]
    public function skipMessage_includes_service_name(): void
    {
        $attr = new RequiresService('redis', host: '10.0.0.1', port: 6379);

        $this->assertStringContainsString('redis', $attr->skipMessage());
        $this->assertStringContainsString('10.0.0.1', $attr->skipMessage());
        $this->assertStringContainsString('6379', $attr->skipMessage());
    }

    #[Test]
    public function daemon8_has_correct_defaults(): void
    {
        $attr = new RequiresDaemon8();

        $this->assertSame('daemon8', $attr->name);
        $this->assertSame(9077, $attr->port);
        $this->assertSame('127.0.0.1', $attr->host);
    }
}
